<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use App\Models\User;
use App\Services\{SipInbound,InboundVoice,TwilioSipRuntime,VoiceAgentCapacity};
class SipInboundTest extends TestCase {
 use RefreshDatabase;
 private User $agent;private int $queue;private int $route;private string $endpoint;
 protected function setUp():void {
  parent::setUp();Http::preventStrayRequests();
  config(['voice_calling_test'=>['event_secret'=>str_repeat('b',64),'sip_password'=>str_repeat('a',64),'max_seconds'=>60,'ring_seconds'=>30],
   'voice_calling_trunk_test'=>['account_sid'=>'AC'.str_repeat('1',32),'trunk_sid'=>'TK'.str_repeat('2',32),'termination_host'=>'fixture.pstn.twilio.com','sip_username'=>'fixture','sip_password'=>'FixturePassword123','caller_id'=>'+551149330254','edge'=>'sao-paulo','planned_concurrency'=>1,'planned_cps'=>1]]);
  $this->agent=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin','voice_enabled'=>true]);$this->actingAs($this->agent);
  $this->queue=DB::table('voice_live_queues')->insertGetId(['workspace_id'=>1,'name'=>'LANÇAMENTOS','direction'=>'inbound','inbound_enabled'=>true,'agent_ids'=>json_encode([$this->agent->id])]);
  $this->route=DB::table('voice_inbound_routes')->insertGetId(['workspace_id'=>1,'queue_id'=>$this->queue,'number'=>'+551149330254','provider'=>'sip_trunk','enabled'=>true]);
  $this->endpoint=app(TwilioSipRuntime::class)->descriptor()['endpoint'];$this->ready($this->agent);
 }
 private function ready(User $u):void {
  DB::table('voice_agent_presence')->updateOrInsert(['user_id'=>$u->id],['workspace_id'=>1,'status'=>'available','last_seen_at'=>now()]);
  DB::table('voice_inbound_devices')->updateOrInsert(['user_id'=>$u->id],['workspace_id'=>1,'session_id'=>(string)Str::uuid(),'identity'=>'unused','sip_identity'=>app(SipInbound::class)->agent($u)['username'],'ready'=>true,'sip_ready'=>true,'last_seen_at'=>now()]);
 }
 private function event(string $event,array $d=[]):array{return app(SipInbound::class)->event($d+['event'=>'inbound_'.$event,'endpoint'=>$this->endpoint,'channel_id'=>'fixture.123']);}
 private function start(array $d=[]):array{return $this->event('start',$d+['number'=>'+551149330254','from'=>'+5511999991111']);}
 public function test_inbound_has_no_daily_ceiling_and_does_not_consume_outbound_limit():void {
  config(['voice_calling_test.daily_limit'=>1]);
  DB::table('ma_operation_policy')->insert(['workspace_id'=>1,'paused'=>false,'voice_daily_limit'=>1,'whatsapp_daily_limit'=>100,'revision'=>1]);
  for($i=0;$i<60;$i++)DB::table('voice_inbound_calls')->insert(['id'=>(string)Str::uuid(),'workspace_id'=>1,'route_id'=>$this->route,'queue_id'=>$this->queue,'account_sid'=>'AC'.str_repeat('a',32),'call_sid'=>'CA'.str_pad((string)$i,32,'0',STR_PAD_LEFT),'from_number'=>'+5511999991111','to_number'=>'+551149330254','status'=>'completed','capacity_released_at'=>now(),'created_at'=>now()]);
  $this->assertNull(app(\App\Services\OperationPolicy::class)->voiceReason(1));
  $this->assertTrue($this->start()['allowed']);
  $this->getJson('/api/voice/health')->assertOk()->assertJsonPath('limits.voice_daily',null)->assertJsonPath('limits.inbound_daily',null)->assertJsonPath('counts.outbound_calls_today',0)->assertJsonPath('counts.inbound_calls_today',61);
 }
 public function test_legacy_outbound_quota_does_not_block_inbound_but_pause_does():void {
  config(['voice_calling_test.daily_limit'=>1]);
  $this->partialMock(\App\Services\OperationPolicy::class,function($mock){$mock->shouldReceive('outboundToday')->andReturn(50);});
  $this->assertNull(app(\App\Services\OperationPolicy::class)->voiceReason(1));
  $this->assertTrue($this->start()['allowed']);
  DB::table('ma_operation_policy')->insert(['workspace_id'=>1,'paused'=>true,'voice_daily_limit'=>50,'whatsapp_daily_limit'=>100,'revision'=>1]);
  $this->assertNotNull(app(\App\Services\OperationPolicy::class)->inboundReason(1));
 }
 public function test_sip_number_catalog_route_configuration_and_personal_receive_only_grant():void {
  $this->assertContains('+551149330254',app(\App\Services\QueueChannels::class)->catalog(1)['incoming_numbers']);
  $this->getJson('/api/voice/inbound')->assertOk()->assertJsonPath('available_numbers.0','+551149330254');
  $this->putJson('/api/voice/inbound/routes/'.$this->route,['number'=>'+551149330254','queue_id'=>$this->queue,'enabled'=>true,'ring_seconds'=>20,'wait_seconds'=>60,'revision'=>1])->assertOk();
  DB::table('voice_inbound_devices')->delete();$sid=(string)Str::uuid();
  $r=$this->postJson('/api/voice/inbound/token',['session_id'=>$sid])->assertOk();$this->assertStringStartsWith('discai-in-',$r->json('sip.username'));$this->assertStringNotContainsString('FixturePassword123',$r->getContent());$this->assertNull($r->json('access_token'));
  $this->postJson('/api/voice/inbound/device',['session_id'=>$sid,'ready'=>true,'sip_ready'=>true])->assertOk();$this->assertDatabaseHas('voice_inbound_devices',['user_id'=>$this->agent->id,'sip_ready'=>true]);
  $this->postJson('/api/voice/inbound/token',['session_id'=>(string)Str::uuid()])->assertConflict();
  $other=User::factory()->create(['voice_workspace_id'=>1,'voice_enabled'=>true]);$this->actingAs($other)->postJson('/api/voice/inbound/token',['session_id'=>(string)Str::uuid()])->assertForbidden();
  Http::assertNothingSent();
 }
 public function test_answer_end_disposition_releases_agent_and_never_dials_a_phone():void {
  $this->assertTrue($this->start()['allowed']);$offer=$this->event('dispatch');$this->assertSame(app(SipInbound::class)->agent($this->agent)['username'],$offer['agent']);
  $this->assertTrue(app(VoiceAgentCapacity::class)->busy(1,$this->agent->id));
  $this->assertTrue($this->event('answer',['offer_id'=>$offer['offer_id']])['allowed']);
  $this->event('end',['bill_seconds'=>12]);$this->event('end',['bill_seconds'=>999]);
  $c=DB::table('voice_inbound_calls')->first();$this->assertSame('tabulation',$c->status);$this->assertSame(12,$c->bill_seconds);
  DB::table('voice_disposition_codes')->insertOrIgnore(['workspace_id'=>1,'code'=>'done','label'=>'Concluído','active'=>true]);
  $this->postJson('/api/voice/inbound/calls/'.$c->id.'/disposition',['code'=>'done','revision'=>1])->assertOk();
  $this->assertFalse(app(VoiceAgentCapacity::class)->inboundBusy(1,$this->agent->id));$this->assertDatabaseCount('voice_outbound_calls',0);Http::assertNothingSent();
 }
 public function test_rejection_moves_to_next_agent_once_and_caller_abandonment_releases_offer():void {
  $other=User::factory()->create(['voice_workspace_id'=>1,'voice_enabled'=>true]);$this->ready($other);DB::table('voice_live_queues')->where('id',$this->queue)->update(['agent_ids'=>json_encode([$this->agent->id,$other->id])]);
  $this->start();$first=$this->event('dispatch');$this->assertTrue($this->event('result',['offer_id'=>$first['offer_id']])['allowed']);$second=$this->event('dispatch');$this->assertNotSame($first['agent'],$second['agent']);
  $this->assertFalse($this->event('result',['offer_id'=>$first['offer_id']])['allowed']);$this->event('end',['bill_seconds'=>0]);
  $this->assertDatabaseHas('voice_inbound_calls',['status'=>'abandoned']);$this->assertFalse(app(VoiceAgentCapacity::class)->inboundBusy(1,$other->id));
 }
 public function test_only_available_registered_agents_receive_and_wait_expires():void {
  DB::table('voice_inbound_devices')->update(['sip_ready'=>false]);$this->start();$this->assertTrue($this->event('dispatch')['waiting']);$this->assertDatabaseCount('voice_inbound_offers',0);
  $this->travel(121)->seconds();$this->assertFalse($this->event('dispatch')['allowed']);$this->event('end',['bill_seconds'=>0]);$this->assertDatabaseHas('voice_inbound_calls',['status'=>'unavailable']);
 }
 public function test_admission_rejects_wrong_trunk_unknown_did_disabled_route_and_duplicate():void {
  $this->assertFalse($this->start(['endpoint'=>'discai-twilio-'.str_repeat('c',32)])['allowed']);
  $this->assertFalse($this->start(['number'=>'+551100000000'])['allowed']);
  DB::table('voice_live_queues')->where('id',$this->queue)->update(['inbound_enabled'=>false]);$this->assertFalse($this->start()['allowed']);
  DB::table('voice_live_queues')->where('id',$this->queue)->update(['inbound_enabled'=>true]);$this->assertTrue($this->start()['allowed']);$this->assertFalse($this->start()['allowed']);
  $this->assertFalse($this->start(['channel_id'=>'fixture.124'])['allowed']);$this->assertDatabaseCount('voice_inbound_calls',1);
 }
 public function test_callback_signature_and_endpoint_are_required():void {
  $body=['event'=>'inbound_start','endpoint'=>$this->endpoint,'channel_id'=>'fixture.123','number'=>'+551149330254','from'=>'+5511999991111'];
  $this->postJson('/internal/voice/calling/event',$body)->assertUnauthorized();$stamp=(string)time();$raw=json_encode($body);
  $this->call('POST','/internal/voice/calling/event',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_VOICE_TIMESTAMP'=>$stamp,'HTTP_X_VOICE_SIGNATURE'=>hash_hmac('sha256',$stamp.'.'.$raw,str_repeat('b',64))],$raw)->assertOk()->assertJsonPath('allowed',true);
 }
 public function test_private_reconciliation_preserves_active_channels_and_recovers_lost_hangup():void {
  $this->start();$this->event('dispatch');app(SipInbound::class)->reconcileChannels([]);$this->assertNull(DB::table('voice_inbound_calls')->value('capacity_released_at'));
  $this->travel(31)->seconds();app(SipInbound::class)->reconcileChannels(['fixture.123']);$this->assertNull(DB::table('voice_inbound_calls')->value('capacity_released_at'));
  app(SipInbound::class)->reconcileChannels([]);$this->assertNotNull(DB::table('voice_inbound_calls')->value('capacity_released_at'));
 }
 public function test_agent_identity_changes_across_tenants_and_password_versions():void {
  $old=app(SipInbound::class)->agent($this->agent);$this->agent->voice_auth_version++;$this->assertNotSame($old,app(SipInbound::class)->agent($this->agent));
  config(['voice_calling_test.event_secret'=>str_repeat('c',64)]);$this->assertNotSame($old,app(SipInbound::class)->agent($this->agent));
 }
}
