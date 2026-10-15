<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use App\Services\{TwilioVoiceConnection,InboundVoice,QueueChannels,VoiceProviderCatalog};
class ManagedNumbersTest extends TestCase {
 use RefreshDatabase;
 private string $requestId='550e8400-e29b-41d4-a716-446655440000';
 protected function setUp():void {parent::setUp();Http::preventStrayRequests();config(['discai_bridge_test'=>['required'=>true,'slug'=>'polishop','secret'=>'own-secret','portal_url'=>'https://app.discai.ia.br'],'twilio_voice_test'=>null]);DB::table('voice_runtime')->insertOrIgnore(['id'=>1]);}
 private function connection():array {return ['account_sid'=>'AC'.str_repeat('a',32),'api_key'=>'SK'.str_repeat('b',32),'api_secret'=>str_repeat('s',32),'auth_token'=>str_repeat('t',32),'application_sid'=>'AP'.str_repeat('c',32),'caller_id'=>'+551130001234','edge'=>'sao-paulo','enabled'=>true];}
 private function payload():array {return ['tenant'=>'polishop','number'=>'+551130001234','request_id'=>$this->requestId,'provider_sid'=>'PN'.str_repeat('d',32)];}
 private function send(array $d,string $secret='own-secret'){$path='discai-internal/numbers';$body=json_encode($d);$time=(string)time();return $this->call('POST','/'.$path,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_DISCAI_TIME'=>$time,'HTTP_X_DISCAI_SIGNATURE'=>hash_hmac('sha256',$time.'.'.$path.'.'.$body,$secret)],$body);}
 public function test_signed_release_is_two_phase_and_never_activates_another_tenant_number():void {
  $d=$this->payload();$this->send($d+['connection'=>$this->connection()],'wrong-secret')->assertUnauthorized();$this->send(array_replace($d,['tenant'=>'another'])+['connection'=>$this->connection()])->assertForbidden();
  $this->send($d+['connection'=>$this->connection()])->assertOk();$this->assertSame([],app(InboundVoice::class)->numbers(1,app(TwilioVoiceConnection::class)->read()));
  $this->send($d+['activate'=>true])->assertOk();$this->send($d+['activate'=>true])->assertOk();$this->assertDatabaseCount('voice_managed_numbers',1);$this->assertDatabaseCount('voice_origins',1);
  $this->assertSame([$d['number']],app(InboundVoice::class)->numbers(1,app(TwilioVoiceConnection::class)->read()));
  $this->send(array_replace($d,['request_id'=>'650e8400-e29b-41d4-a716-446655440000'])+['activate'=>true])->assertConflict();Http::assertNothingSent();
 }
 public function test_catalog_never_imports_numbers_from_shared_parent_account():void {
  $d=$this->payload();$this->send($d+['connection'=>$this->connection()])->assertOk();$this->send($d+['activate'=>true])->assertOk();
  DB::table('voice_origins')->insert(['workspace_id'=>1,'account_sid'=>$this->connection()['account_sid'],'provider_sid'=>'PN'.str_repeat('e',32),'number'=>'+552130001234','kind'=>'owned','enabled'=>true,'verified_at'=>now()]);
  $catalog=app(QueueChannels::class)->catalog(1);$this->assertSame([$d['number']],$catalog['incoming_numbers']);$this->assertCount(1,$catalog['voice_numbers']);app(VoiceProviderCatalog::class)->sync(1);Http::assertNothingSent();
  try{app(QueueChannels::class)->origin((object)['voice_number'=>'+552130001234','workspace_id'=>1],app(TwilioVoiceConnection::class)->read());$this->fail('Cross-tenant number accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$this->assertSame(422,$e->getStatusCode());}
 }
 public function test_existing_independent_connection_is_not_replaced():void {
  config(['twilio_voice_test'=>$this->connection()]);$this->send($this->payload()+['connection'=>$this->connection()])->assertConflict();$this->assertDatabaseCount('voice_managed_numbers',0);
 }
 public function test_tenant_cannot_replace_managed_credentials():void {
  config(['twilio_voice_test'=>$this->connection()+['managed_by_portal'=>true]]);
  try{app(TwilioVoiceConnection::class)->save($this->connection());$this->fail('Managed credentials replaced');}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$this->assertSame(409,$e->getStatusCode());}
 }
}
