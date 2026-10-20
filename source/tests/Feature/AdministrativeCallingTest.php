<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\{AdministrativeCalling,VoiceCalling,VoiceProviderCatalog};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
class AdministrativeCallingTest extends TestCase {
 use RefreshDatabase;
 private User $user;private int $contact;
 protected function setUp():void {
  parent::setUp();Http::preventStrayRequests();
  config(['voice_calling_test'=>['allowed_recipients'=>['+5511999990001'],'daily_limit'=>20,'max_seconds'=>30,'ring_seconds'=>10,'caller_id_confirmed'=>true,'sip_username'=>'ma-calling','sip_password'=>str_repeat('a',64),'event_secret'=>str_repeat('b',64)],'twilio_voice_test'=>['account_sid'=>'AC'.str_repeat('1',32),'api_key'=>'SK'.str_repeat('2',32),'api_secret'=>'fixture-secret','application_sid'=>'AP'.str_repeat('3',32),'caller_id'=>'+551130000001','edge'=>'ashburn','enabled'=>true],'discai_bridge_test'=>['slug'=>'example','secret'=>'bridge-secret','internal_url'=>'https://portal.example']]);
  Http::fake(['https://portal.example/*'=>Http::response(['tenant'=>'example','role'=>'admin','platform_admin'=>true,'name'=>'Admin','email'=>'admin@example.test'])]);
  $this->user=User::factory()->create(['email'=>'admin@example.test']);$this->user->forceFill(['voice_workspace_id'=>1,'voice_role'=>'admin'])->save();
  $this->contact=DB::table('voice_contacts')->insertGetId(['workspace_id'=>1,'name'=>'Fixture','phone'=>'+5511999990001','original_phone'=>'+5511999990001','source'=>'Fixture','consent'=>true,'consent_evidence'=>'Own test phone']);
 }
 private function data(array $extra=[]):array {return $extra+['method'=>'programmable_voice','origin'=>'+551130000001','destination'=>'+5511999990001','request_id'=>(string)Str::uuid(),'consent_confirmed'=>true,'consent_evidence'=>'Own test phone'];}
 private function reject(callable $f,int $status):void {try{$f();$this->fail('Expected rejection');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame($status,$e->getStatusCode());}}
 private function signed(array $d,?string $sig=null){$body=json_encode(['tenant'=>'example','operator'=>10]+$d);$stamp=(string)time();return $this->call('POST','/discai-internal/calling',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_DISCAI_TIME'=>$stamp,'HTTP_X_DISCAI_SIGNATURE'=>$sig??hash_hmac('sha256',$stamp.'.discai-internal/calling.'.$body,'bridge-secret')],$body);}
 public function test_admin_can_reserve_without_queue_or_online_and_no_provider_dial():void {
  $d=$this->data();$g=app(AdministrativeCalling::class)->reserve($this->user->id,10,$d);$c=DB::table('voice_outbound_calls')->find($g['id']);
  $this->assertTrue((bool)$c->manual);$this->assertNull($c->queue_id);$this->assertNull($c->campaign_id);$this->assertNull($c->started_at);$this->assertSame('pending',$c->status);$this->assertSame(10,json_decode($c->context_snapshot,true)['platform_operator']);
  $this->assertSame($g['id'],app(AdministrativeCalling::class)->reserve($this->user->id,10,$d)['id']);
  $this->assertDatabaseCount('voice_followups',0);Http::assertNothingSent();
  app(VoiceCalling::class)->cancel(1,$this->user->id,$c->id);$this->assertTrue(app(AdministrativeCalling::class)->state(10,$d['request_id'])['ended']);
 }
 public function test_verified_alternate_origin_is_used_and_revocation_blocks_dial():void {
  DB::table('voice_origins')->insert(['workspace_id'=>1,'account_sid'=>config('twilio_voice_test.account_sid'),'provider_sid'=>'PN'.str_repeat('4',32),'number'=>'+551130000002','kind'=>'owned','enabled'=>true,'verified_at'=>now()]);
  $g=app(AdministrativeCalling::class)->reserve($this->user->id,10,$this->data(['origin'=>'+551130000002']));$c=DB::table('voice_outbound_calls')->find($g['id']);$this->assertSame('+551130000002',$c->caller_id);
  app(VoiceProviderCatalog::class)->assertOrigin($c);DB::table('voice_origins')->update(['enabled'=>false]);$this->reject(fn()=>app(VoiceProviderCatalog::class)->assertOrigin($c),422);
  Http::assertNotSent(fn($r)=>str_contains($r->url(),'twilio.com'));
 }
 public function test_destinations_origin_consent_and_concurrency_are_enforced():void {
  $s=app(AdministrativeCalling::class);
  $this->reject(fn()=>$s->reserve($this->user->id,10,$this->data(['origin'=>'+551130000099'])),422);
  DB::table('voice_contacts')->where('id',$this->contact)->update(['suppressed_at'=>now()]);$this->reject(fn()=>$s->reserve($this->user->id,10,$this->data()),422);
  DB::table('voice_contacts')->where('id',$this->contact)->update(['suppressed_at'=>null]);config(['voice_calling_test.allowed_recipients'=>['+5511999990002']]);$this->reject(fn()=>$s->reserve($this->user->id,10,$this->data()),422);
  config(['voice_calling_test.allowed_recipients'=>['+5511999990001']]);$d=$this->data();$s->reserve($this->user->id,10,$d);$this->reject(fn()=>$s->reserve($this->user->id,10,$this->data()),409);$this->reject(fn()=>$s->state(11,$d['request_id']),403);
 }
 public function test_signed_bridge_and_live_platform_role_are_required():void {
  $this->signed(['action'=>'catalog'],'invalid')->assertUnauthorized();$this->signed(['action'=>'catalog'])->assertOk()->assertJsonPath('numbers.0.number','+551130000001');
  Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();Http::fake(['https://portal.example/*'=>Http::response(['tenant'=>'example','role'=>'admin','platform_admin'=>false])]);$this->signed(['action'=>'catalog'])->assertForbidden();$this->signed(['action'=>'reserve']+$this->data())->assertForbidden();$this->assertDatabaseCount('voice_outbound_calls',0);
 }
 public function test_bridge_reservation_and_ownership_and_public_api_cannot_inject_platform_flag():void {
  $d=$this->data();$this->signed(['action'=>'reserve']+$d)->assertOk();$this->signed(['action'=>'status','request_id'=>$d['request_id']])->assertOk()->assertJsonPath('call.status','pending');
  $this->signed(['action'=>'cancel','request_id'=>$d['request_id']])->assertOk()->assertJsonPath('call.ended',true);
  $this->user->forceFill(['voice_role'=>'agent'])->save();$this->actingAs($this->user)->postJson('/api/voice/calling/calls',['method'=>'programmable_voice','contact_id'=>$this->contact,'idempotency_key'=>(string)Str::uuid(),'consent_confirmed'=>true,'consent_evidence'=>'Own test phone','_platform_test'=>['operator'=>10,'origin'=>'+551130000001']])->assertForbidden();
 }
 public function test_fale_vono_admin_call_uses_configured_trunk_and_confirms_busy_without_real_dial():void {
  config(['voice_audio_test'=>['enabled'=>true,'sip_username'=>'ma-audio','sip_password'=>'echo-only','event_secret'=>'echo-secret']]);
  Http::fake(['http://10.241.50.10:8088/httpstatus'=>Http::response('OK')]);
  $id=(string)Str::uuid();$saved=app(\App\Services\SipTrunks::class)->save(1,$this->user->id,['id'=>$id,'provider'=>'fale_vono','label'=>'QA','number'=>'+551130009988','host'=>'vono2.me','username'=>'fixture-user','password'=>'OnlyTest1234','port'=>5060,'transport'=>'udp','dial_format'=>'national','concurrency'=>1,'cps'=>1,'enabled'=>true]);
  $sip=app(\App\Services\SipTrunks::class);$row=DB::table('voice_sip_trunks')->find($id);DB::table('voice_sip_trunks')->where('id',$id)->update(['registration_state'=>'registered','checked_at'=>now(),'applied_hash'=>$sip->hash($row)]);
  $d=$this->data(['method'=>'sip_trunk','origin'=>$row->number]);$g=app(AdministrativeCalling::class)->reserve($this->user->id,10,$d);$this->assertStringNotContainsString('OnlyTest1234',json_encode($g));$c=DB::table('voice_outbound_calls')->find($g['id']);$this->assertNull($c->queue_id);$this->assertSame($row->number,$c->caller_id);
  preg_match('~sip:([a-f0-9]{64})@~',$g['destination'],$m);$svc=app(VoiceCalling::class);$start=$svc->event(['token'=>$m[1],'event'=>'start','channel_id'=>'fixture.1']);$this->assertTrue($start['allowed']);$this->assertSame('11999990001',$start['dial_number']);$this->assertSame($sip->endpoint($row),$start['endpoint']);
  $svc->event(['token'=>$m[1],'event'=>'finish','channel_id'=>'fixture.1','dial_status'=>'BUSY','cause'=>'17','bill_seconds'=>0]);$state=app(AdministrativeCalling::class)->state(10,$d['request_id']);$this->assertSame('busy',$state['status']);$this->assertTrue($state['ended']);$this->assertDatabaseCount('voice_followups',0);
  Http::assertNotSent(fn($r)=>str_contains($r->url(),'vono')||str_contains($r->url(),'twilio'));
 }

}
