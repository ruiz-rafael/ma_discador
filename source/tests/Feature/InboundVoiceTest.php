<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\{InboundVoice,VoiceAgentCapacity,VoiceLiveQueue,TwilioVoiceConnection};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;
use Twilio\Security\RequestValidator;
class InboundVoiceTest extends TestCase {
 use RefreshDatabase;
 private User $user;private int $queue;private int $route;
 protected function setUp():void {parent::setUp();Http::preventStrayRequests();$this->user=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin']);$this->actingAs($this->user);config(['twilio_voice_test'=>['account_sid'=>'AC'.str_repeat('a',32),'api_key'=>'SK'.str_repeat('b',32),'api_secret'=>str_repeat('s',32),'auth_token'=>str_repeat('t',32),'application_sid'=>'AP'.str_repeat('c',32),'caller_id'=>'+16890000000','edge'=>'ashburn','enabled'=>true]]);$campaign=DB::table('voice_campaigns')->insertGetId(['workspace_id'=>1,'name'=>'QA','settings'=>'{}']);$this->queue=DB::table('voice_live_queues')->insertGetId(['workspace_id'=>1,'campaign_id'=>$campaign,'name'=>'QA','agent_ids'=>json_encode([$this->user->id])]);$this->route=DB::table('voice_inbound_routes')->insertGetId(['workspace_id'=>1,'queue_id'=>$this->queue,'number'=>'+16890000000','enabled'=>true]);$this->ready($this->user);}
 private function ready(User $u):void {DB::table('voice_agent_presence')->updateOrInsert(['user_id'=>$u->id],['workspace_id'=>1,'status'=>'available','last_seen_at'=>now()]);DB::table('voice_inbound_devices')->updateOrInsert(['user_id'=>$u->id],['workspace_id'=>1,'session_id'=>(string)Str::uuid(),'identity'=>'agent_'.$u->id,'ready'=>true,'last_seen_at'=>now()]);}
 private function receive():string {return app(InboundVoice::class)->receive(['AccountSid'=>'AC'.str_repeat('a',32),'CallSid'=>'CA'.str_repeat('1',32),'From'=>'+5511999991111','To'=>'+16890000000']);}
 private function row():object{return DB::table('voice_inbound_calls')->first();}
 private function offer():object{return DB::table('voice_inbound_offers')->first();}
 public function test_inbound_reserves_agent_and_blocks_outbound_until_disposition():void {
  $xml=$this->receive();$this->assertStringContainsString('<Client',$xml);$this->assertStringContainsString('agent_'.$this->user->id,$xml);$this->assertTrue(app(VoiceAgentCapacity::class)->inboundBusy(1,$this->user->id));
  $claim=app(VoiceLiveQueue::class)->claim(1,$this->user->id,$this->queue,(string)Str::uuid());$this->assertNull($claim['reservation']);$this->assertStringContainsString('receptivo',$claim['message']);
  $c=$this->row();$o=$this->offer();app(InboundVoice::class)->offerStatus($o->id,['AccountSid'=>$c->account_sid,'ParentCallSid'=>$c->call_sid,'CallSid'=>'CA'.str_repeat('2',32),'CallStatus'=>'in-progress']);app(InboundVoice::class)->ended(['AccountSid'=>$c->account_sid,'CallSid'=>$c->call_sid,'CallStatus'=>'completed','CallDuration'=>20]);
  $this->assertTrue(app(VoiceAgentCapacity::class)->inboundBusy(1,$this->user->id));$this->assertDatabaseHas('voice_inbound_calls',['id'=>$c->id,'status'=>'tabulation','bill_seconds'=>20]);
  $code=DB::table('voice_disposition_codes')->where('workspace_id',1)->where('active',true)->value('code');if(!$code){DB::table('voice_disposition_codes')->insert(['workspace_id'=>1,'code'=>'done','label'=>'Concluído','active'=>true]);$code='done';}
  $this->postJson('/api/voice/inbound/calls/'.$c->id.'/disposition',['code'=>$code,'revision'=>1,'notes'=>'Conversa de QA'])->assertOk();$this->assertFalse(app(VoiceAgentCapacity::class)->inboundBusy(1,$this->user->id));Http::assertNothingSent();
 }
 public function test_no_answer_redistributes_once_and_abandonment_releases_all_offers():void {
  $second=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'agent']);$this->ready($second);DB::table('voice_live_queues')->where('id',$this->queue)->update(['agent_ids'=>json_encode([$this->user->id,$second->id])]);$this->receive();$o=$this->offer();$c=$this->row();$xml=app(InboundVoice::class)->finish($o->id,['AccountSid'=>$c->account_sid,'CallSid'=>$c->call_sid,'DialCallStatus'=>'no-answer']);$this->assertStringContainsString('agent_'.$second->id,$xml);$this->assertDatabaseCount('voice_inbound_offers',2);
  app(InboundVoice::class)->finish($o->id,['AccountSid'=>$c->account_sid,'CallSid'=>$c->call_sid,'DialCallStatus'=>'no-answer']);$this->assertDatabaseCount('voice_inbound_offers',2);
  app(InboundVoice::class)->ended(['AccountSid'=>$c->account_sid,'CallSid'=>$c->call_sid,'CallStatus'=>'completed','CallDuration'=>2]);$this->assertFalse(app(VoiceAgentCapacity::class)->inboundBusy(1,$second->id));$this->assertDatabaseHas('voice_inbound_calls',['id'=>$c->id,'status'=>'abandoned']);
 }
 public function test_disconnected_agent_not_rung_and_wait_expiration_does_not_free_without_provider_confirmation():void {
  DB::table('voice_inbound_devices')->update(['last_seen_at'=>now()->subMinutes(2)]);$this->assertStringContainsString('<Pause',$this->receive());$this->assertDatabaseCount('voice_inbound_offers',0);$this->travel(3)->minutes();$c=$this->row();$this->assertStringContainsString('<Hangup',app(InboundVoice::class)->dispatch($c->id,$c->call_sid));$this->assertDatabaseHas('voice_inbound_calls',['id'=>$c->id,'status'=>'unavailable','capacity_released_at'=>null]);
 }
 public function test_session_cannot_be_taken_over_by_second_browser_and_token_cannot_dial_out():void {
  DB::table('voice_inbound_devices')->delete();$session=(string)Str::uuid();$r=$this->postJson('/api/voice/inbound/token',['session_id'=>$session])->assertOk();$parts=explode('.',$r->json('access_token'));$jwt=json_decode(base64_decode(strtr($parts[1],'-_','+/')),true);$this->assertTrue($jwt['grants']['voice']['incoming']['allow']);$this->assertArrayNotHasKey('outgoing',$jwt['grants']['voice']);$this->postJson('/api/voice/inbound/token',['session_id'=>(string)Str::uuid()])->assertConflict();
 }
 public function test_explicit_release_allows_immediate_reload_and_late_old_requests_cannot_change_new_device():void {
  $old=DB::table('voice_inbound_devices')->value('session_id');$next=(string)Str::uuid();
  $this->postJson('/api/voice/inbound/device',['session_id'=>$old,'ready'=>false])->assertOk();
  $this->assertDatabaseCount('voice_inbound_devices',0);
  $this->postJson('/api/voice/inbound/token',['session_id'=>$next])->assertOk();
  $this->postJson('/api/voice/inbound/device',['session_id'=>$next,'ready'=>true])->assertOk();
  $this->postJson('/api/voice/inbound/device',['session_id'=>$old,'ready'=>false])->assertOk();
  $this->postJson('/api/voice/inbound/device',['session_id'=>$old,'ready'=>true])->assertConflict();
  $this->assertDatabaseHas('voice_inbound_devices',['user_id'=>$this->user->id,'session_id'=>$next,'ready'=>true]);
  $this->postJson('/api/voice/inbound/token',['session_id'=>(string)Str::uuid()])->assertConflict();
  $this->assertDatabaseCount('voice_outbound_calls',0);Http::assertNothingSent();
 }
 public function test_release_during_inbound_offer_keeps_identity_and_prevents_takeover_even_after_lease_expires():void {
  $old=DB::table('voice_inbound_devices')->value('session_id');$this->receive();
  $this->postJson('/api/voice/inbound/device',['session_id'=>$old,'ready'=>false])->assertOk();
  $this->assertDatabaseHas('voice_inbound_devices',['user_id'=>$this->user->id,'session_id'=>$old,'ready'=>false]);
  $this->travel(65)->seconds();
  $this->postJson('/api/voice/inbound/token',['session_id'=>(string)Str::uuid()])->assertConflict();
  $this->assertTrue(app(VoiceAgentCapacity::class)->inboundBusy(1,$this->user->id));Http::assertNothingSent();
 }
 public function test_missing_release_still_protects_a_pending_registration_until_timeout():void {
  DB::table('voice_inbound_devices')->delete();$old=(string)Str::uuid();$next=(string)Str::uuid();
  $this->postJson('/api/voice/inbound/token',['session_id'=>$old])->assertOk();
  $this->postJson('/api/voice/inbound/token',['session_id'=>$next])->assertConflict()->assertJsonPath('message','A conexão anterior do receptivo ainda está registrada. Se acabou de recarregar, aguarde até 60 segundos e tente ficar online novamente. Se houver outra aba atendendo, use aquela aba.');
  $this->travel(61)->seconds();$this->postJson('/api/voice/inbound/token',['session_id'=>$next])->assertOk();
  $this->assertDatabaseHas('voice_inbound_devices',['user_id'=>$this->user->id,'session_id'=>$next,'ready'=>false]);Http::assertNothingSent();
 }
 public function test_callback_requires_valid_signature_and_account():void {
  $d=['AccountSid'=>'AC'.str_repeat('a',32),'CallSid'=>'CA'.str_repeat('1',32),'From'=>'+5511999991111','To'=>'+16890000000'];$url='/callbacks/twilio/voice/inbound';$this->post($url,$d,['Content-Type'=>'application/x-www-form-urlencoded'])->assertForbidden();$sig=(new RequestValidator(str_repeat('t',32)))->computeSignature(InboundVoice::BASE,$d);$this->post($url,$d,['Content-Type'=>'application/x-www-form-urlencoded','X-Twilio-Signature'=>$sig])->assertOk();$this->assertDatabaseCount('voice_inbound_calls',1);
 }
 public function test_transfer_holds_both_agents_until_provider_confirms_redirect():void {
  $second=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'agent']);$this->ready($second);DB::table('voice_live_queues')->where('id',$this->queue)->update(['agent_ids'=>json_encode([$this->user->id,$second->id])]);$this->receive();$c=$this->row();$o=$this->offer();app(InboundVoice::class)->offerStatus($o->id,['AccountSid'=>$c->account_sid,'ParentCallSid'=>$c->call_sid,'CallSid'=>'CA'.str_repeat('2',32),'CallStatus'=>'in-progress']);Http::fake(['api.twilio.com/*'=>Http::response(['sid'=>$c->call_sid],200)]);$response=$this->postJson('/api/voice/inbound/calls/'.$c->id.'/transfer',['user_id'=>$second->id])->assertOk();$this->assertTrue(app(VoiceAgentCapacity::class)->inboundBusy(1,$this->user->id));$this->assertTrue(app(VoiceAgentCapacity::class)->inboundBusy(1,$second->id));$xml=app(InboundVoice::class)->transferDial($response->json('offer_id'),['CallSid'=>$c->call_sid,'AccountSid'=>$c->account_sid]);$this->assertStringContainsString('agent_'.$second->id,$xml);$this->assertFalse(app(VoiceAgentCapacity::class)->inboundBusy(1,$this->user->id));
 }
 public function test_parent_completion_before_child_answer_still_records_answered_tabulation():void {
  $this->receive();$c=$this->row();$o=$this->offer();$s=app(InboundVoice::class);$s->ended(['AccountSid'=>$c->account_sid,'CallSid'=>$c->call_sid,'CallStatus'=>'completed','CallDuration'=>20]);$this->assertDatabaseHas('voice_inbound_calls',['id'=>$c->id,'status'=>'abandoned']);
  $s->offerStatus($o->id,['AccountSid'=>$c->account_sid,'ParentCallSid'=>$c->call_sid,'CallSid'=>'CA'.str_repeat('2',32),'CallStatus'=>'completed']);$this->assertDatabaseHas('voice_inbound_calls',['id'=>$c->id,'status'=>'tabulation']);$this->assertNotNull($this->row()->capacity_released_at);
 }
 public function test_publishing_route_refuses_other_routing_and_is_idempotent_when_configured():void {
  $n=['sid'=>'PN'.str_repeat('3',32),'account_sid'=>'AC'.str_repeat('a',32),'phone_number'=>'+16890000000','voice_url'=>'https://other.example.com/voice','status_callback'=>'','voice_method'=>'POST','status_callback_method'=>'POST'];
  $other=$n;$n['voice_url']=InboundVoice::BASE;$n['status_callback']=InboundVoice::BASE.'/status';Http::fake(['api.twilio.com/*'=>Http::sequence()->push(['incoming_phone_numbers'=>[$other],'next_page_uri'=>null])->push(['incoming_phone_numbers'=>[$n],'next_page_uri'=>null])]);$this->postJson('/api/voice/inbound/routes/'.$this->route.'/publish',[])->assertConflict();
  $this->postJson('/api/voice/inbound/routes/'.$this->route.'/publish',[])->assertOk()->assertJsonPath('configured',true);
  $this->assertDatabaseHas('voice_inbound_routes',['id'=>$this->route,'provider_number_sid'=>$n['sid']]);Http::assertNotSent(fn($request)=>$request->method()==='POST');
 }
 public function test_internal_diagnostic_prevents_inbound_admission_and_queue_claim():void {
  DB::table('voice_audio_sessions')->insert(['id'=>(string)Str::uuid(),'workspace_id'=>1,'user_id'=>$this->user->id,'idempotency_key'=>(string)Str::uuid(),'grant_expires_at'=>now(),'grant_hash'=>hash('sha256','qa'),'status'=>'active','expires_at'=>now()->addMinute(),'created_at'=>now(),'updated_at'=>now()]);
  $this->assertStringContainsString('<Reject',$this->receive());$this->assertDatabaseCount('voice_inbound_calls',0);
  $result=app(VoiceLiveQueue::class)->claim(1,$this->user->id,$this->queue,(string)Str::uuid());$this->assertNull($result['reservation']);$this->assertDatabaseCount('voice_live_reservations',0);Http::assertNothingSent();
 }
 public function test_inbound_wrapup_counts_from_hangup_and_disposition_does_not_restart_it():void {
  DB::table('voice_live_queues')->where('id',$this->queue)->update(['wrapup_seconds'=>120]);$this->receive();$c=$this->row();$o=$this->offer();$service=app(InboundVoice::class);$service->offerStatus($o->id,['AccountSid'=>$c->account_sid,'ParentCallSid'=>$c->call_sid,'CallSid'=>'CA'.str_repeat('2',32),'CallStatus'=>'in-progress']);$service->ended(['AccountSid'=>$c->account_sid,'CallSid'=>$c->call_sid,'CallStatus'=>'completed','CallDuration'=>20]);$until=DB::table('voice_agent_presence')->where('user_id',$this->user->id)->value('available_after');$this->assertNotNull($until);$this->travel(20)->seconds();DB::table('voice_disposition_codes')->insertOrIgnore(['workspace_id'=>1,'code'=>'qa_done','label'=>'Concluído','active'=>true]);$this->postJson('/api/voice/inbound/calls/'.$c->id.'/disposition',['code'=>'qa_done','revision'=>$this->row()->revision])->assertOk();$this->assertSame($until,DB::table('voice_agent_presence')->where('user_id',$this->user->id)->value('available_after'));Http::assertNothingSent();
 }

}
