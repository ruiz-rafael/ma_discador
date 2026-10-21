<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use App\Models\User;
use App\Services\{TwilioSipRuntime,VoiceCallingConfig,VoiceCalling,ManualDial,SipTrunks};
class TenantTwilioSipTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void {
  parent::setUp();Http::preventStrayRequests();Http::fake(['http://10.241.50.10:8088/httpstatus'=>Http::response('OK')]);
  config(['voice_calling_test'=>['event_secret'=>str_repeat('b',64),'sip_password'=>str_repeat('a',64),'sip_username'=>'ma-calling','allowed_recipients'=>['+5511999990001'],'daily_limit'=>10,'max_seconds'=>60,'ring_seconds'=>30,'caller_id_confirmed'=>true],
  'voice_calling_trunk_test'=>['account_sid'=>'AC'.str_repeat('1',32),'trunk_sid'=>'TK'.str_repeat('2',32),'termination_host'=>'fixture.pstn.twilio.com','sip_username'=>'fixture','sip_password'=>'FixturePassword123','caller_id'=>'+551149330254','edge'=>'sao-paulo','planned_concurrency'=>1,'planned_cps'=>1], 'voice_audio_test'=>[]]);
 }
 private function install():void {$s=app(TwilioSipRuntime::class);$s->record($s->descriptor()+['state'=>'installed']);}
 public function test_tenant_identity_tls_render_and_fail_closed_status():void {
  $s=app(TwilioSipRuntime::class);$d=$s->descriptor();$e=$s->export();
  $this->assertFalse($s->ready());$this->install();$this->assertTrue($s->ready());
  $this->assertTrue(app(VoiceCallingConfig::class)->status()['ready']);
  $this->assertStringContainsString('media_encryption=sdes',$e['configuration']);
  $this->assertStringContainsString('context=discai-sip-denied',$e['configuration']);
  $this->assertStringNotContainsString('[ma-twilio-out]',$e['configuration']);
  $this->assertStringContainsString('verify_server=yes',$e['transport_configuration']);
  $this->travel(151)->seconds();$this->assertFalse($s->ready());$this->travelBack();
  config(['voice_calling_test.event_secret'=>str_repeat('c',64)]);
  $this->assertNotSame($d['endpoint'],$s->descriptor()['endpoint']);$this->assertFalse($s->ready());
  $s->record($d+['state'=>'installed']);$this->assertFalse($s->ready());
  $this->assertDatabaseCount('voice_outbound_calls',0);
 }
 public function test_manual_grant_uses_tenant_browser_and_correct_trunk_without_carrier_calls():void {
  $this->install();$u=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin','voice_enabled'=>true]);$this->actingAs($u);
  $this->getJson('/api/voice/audio/authorize')->assertNoContent();
  $cid=DB::table('voice_contacts')->insertGetId(['workspace_id'=>1,'name'=>'Fixture','phone'=>'+5511999990001','original_phone'=>'+5511999990001','source'=>'fixture','consent'=>true,'consent_evidence'=>'Isolated test']);
  $qid=DB::table('voice_live_queues')->insertGetId(['workspace_id'=>1,'name'=>'Twilio SIP','direction'=>'outbound','manual_enabled'=>true,'calling_method'=>'sip_trunk','channels_configured'=>true,'voice_number'=>'+551149330254','agent_ids'=>json_encode([$u->id])]);
  $this->assertCount(1,app(ManualDial::class)->origins(1,$u->id));
  $r=app(ManualDial::class)->reserve(1,$u->id,['number'=>'+5511999990001','queue_id'=>$qid,'idempotency_key'=>(string)Str::uuid(),'session_id'=>(string)Str::uuid()])['reservation'];
  $g=app(VoiceCalling::class)->reserve(1,$u->id,['contact_id'=>$cid,'queue_reservation_id'=>$r->id,'idempotency_key'=>(string)Str::uuid(),'consent_confirmed'=>true,'consent_evidence'=>'Isolated test']);
  $this->assertSame(app(SipTrunks::class)->browser()['username'],$g['username']);
  $this->assertStringNotContainsString('FixturePassword123',json_encode($g));
  preg_match('~sip:([a-f0-9]{64})@~',$g['destination'],$m);
  $start=app(VoiceCalling::class)->event(['token'=>$m[1],'event'=>'start','channel_id'=>'fixture.1']);
  $this->assertTrue($start['allowed']);$this->assertSame(app(TwilioSipRuntime::class)->descriptor()['endpoint'],$start['endpoint']);
  $this->assertSame('+5511999990001',$start['dial_number']);
  app(VoiceCalling::class)->event(['token'=>$m[1],'event'=>'finish','channel_id'=>'fixture.1','dial_status'=>'BUSY','cause'=>'17','bill_seconds'=>0]);
  $this->assertDatabaseHas('voice_outbound_calls',['id'=>$g['id'],'status'=>'busy']);
  Http::assertNotSent(fn($r)=>$r->method()==='POST');
 }
}
