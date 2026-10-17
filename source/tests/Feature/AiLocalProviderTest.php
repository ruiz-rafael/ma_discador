<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\{AiLocalProvider,AiProvider,AiAgents};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Crypt};
use Illuminate\Support\Str;

class AiLocalProviderTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void {parent::setUp();Http::preventStrayRequests();config(['ai_local.trusted_origins'=>['http://qwen.test']]);$this->actingAs(User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin']));}
 private function settings(array $extra=[]):array {return array_replace(['text_provider'=>'qwen','endpoint'=>'http://qwen.test','api_key'=>'fictional-private-server-key','model'=>'qwen3:8b','enabled'=>true,'daily_requests'=>100,'context_tokens'=>8192,'parallel_requests'=>1,'timeout_seconds'=>35,'revision'=>0],$extra);}
 private function fake(array $extra=[]):void {Http::swap(new \Illuminate\Http\Client\Factory());Http::preventStrayRequests();Http::fake($extra+['http://qwen.test/api/tags'=>Http::response(['models'=>[['name'=>'qwen3:8b']]]),'http://qwen.test/api/chat'=>Http::response(['done'=>true,'done_reason'=>'stop','message'=>['content'=>json_encode(['reply'=>'Olá, como posso ajudar?','action'=>'reply','summary'=>'Orientação em português.'])],'prompt_eval_count'=>50,'eval_count'=>20])]);}
 private function connect(array $extra=[]):void {$this->fake();app(AiLocalProvider::class)->save(1,$this->settings($extra));}
 private function newSession():string {$c=AiAgents::defaults();$c['knowledge']=[['question'=>'Horário','answer'=>'Das 9h às 18h.']];$id=$this->postJson('/api/ai/agents',['config'=>$c])->assertOk()->json('id');return $this->postJson('/api/ai/agents/'.$id.'/start',['mode'=>'premium','cost_acknowledged'=>true])->assertOk()->json('id');}
 private function turn(string $id,string $text='Olá'){return $this->postJson('/api/ai/sessions/'.$id.'/turn',['request_id'=>(string)Str::uuid(),'input'=>$text]);}
 public function test_qwen_runs_without_openai_key_and_keeps_voice_unavailable():void {
  $this->connect();$id=$this->newSession();$this->turn($id)->assertOk()->assertJsonPath('output','Olá, como posso ajudar?');
  $this->assertDatabaseHas('ai_sessions',['id'=>$id,'provider'=>'qwen','model'=>'qwen3:8b','input_tokens'=>50,'output_tokens'=>20,'estimated_usd'=>0]);
  Http::assertSent(fn($r)=>$r->url()==='http://qwen.test/api/chat'&&$r['think']===false&&$r['stream']===false&&$r['format']['properties']['action']['enum']===['reply','handoff','stop']&&$r['messages'][0]['role']==='system');
  Http::assertNotSent(fn($r)=>str_contains($r->url(),'openai.com'));$this->assertFalse(app(AiProvider::class)->connection(1)['voice_enabled']);$this->assertDatabaseCount('voice_outbound_calls',0);$this->assertDatabaseCount('wa_messages',0);
 }
 public function test_secrets_are_encrypted_and_workspace_isolation_is_preserved():void {
  $this->connect();$r=app(AiLocalProvider::class)->row(1);$this->assertSame('fictional-private-server-key',Crypt::decryptString($r->credentials));$this->assertNotEquals('fictional-private-server-key',$r->credentials);
  $this->getJson('/api/ai/agents')->assertDontSee('fictional-private-server-key')->assertDontSee('qwen.test');$this->assertFalse(app(AiProvider::class)->connection(2)['text_enabled']);
 }
 public function test_disabled_configuration_does_not_contact_or_install_model():void {
  app(AiLocalProvider::class)->save(1,$this->settings(['enabled'=>false]));Http::assertNothingSent();
  $c=AiAgents::defaults();$id=$this->postJson('/api/ai/agents',['config'=>$c])->json('id');$this->postJson('/api/ai/agents/'.$id.'/start',['mode'=>'premium','cost_acknowledged'=>true])->assertConflict();
 }
 public function test_missing_model_cannot_be_enabled():void {
  Http::fake(['http://qwen.test/api/tags'=>Http::response(['models'=>[]])]);try{app(AiLocalProvider::class)->save(1,$this->settings());$this->fail();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}$this->assertDatabaseCount('ai_local_connections',0);
 }
 public function test_invalid_answers_and_upstream_failures_never_fall_back_to_gpt():void {
  $this->connect();foreach([Http::response([],429),Http::response('error',500),Http::response(['done'=>true,'done_reason'=>'length']),Http::response(['done'=>true,'done_reason'=>'stop','message'=>['content'=>'not JSON']]),Http::response(['done'=>true,'done_reason'=>'stop','message'=>['content'=>json_encode(['reply'=>' ','action'=>'reply','summary'=>''])]])]as $response){$this->fake(['http://qwen.test/api/chat'=>$response]);$id=$this->newSession();$this->turn($id)->assertStatus(502);$this->assertDatabaseHas('ai_sessions',['id'=>$id,'status'=>'handoff']);}Http::assertNotSent(fn($r)=>str_contains($r->url(),'openai.com'));$this->assertDatabaseCount('wa_messages',0);
 }
 public function test_daily_and_parallel_limits_prevent_extra_inference():void {
  $this->connect(['daily_requests'=>1]);$id=$this->newSession();$this->turn($id)->assertOk();$this->turn($id)->assertStatus(429);
  DB::table('ai_local_connections')->update(['daily_requests'=>100]);$a=$this->newSession();$b=$this->newSession();DB::table('ai_turns')->insert(['id'=>(string)Str::uuid(),'session_id'=>$a,'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'sequence'=>1,'input'=>'Oi','status'=>'processing','created_at'=>now(),'updated_at'=>now()]);$this->turn($b)->assertStatus(429);
  $this->assertSame(1,count(Http::recorded(fn($r)=>str_ends_with($r->url(),'/api/chat'))));
 }
 public function test_policy_stops_before_inference_and_context_is_never_silently_truncated():void {
  $this->connect();$id=$this->newSession();$this->turn($id,'Pare de enviar mensagens')->assertOk()->assertJsonPath('action','stop');
  try{app(AiLocalProvider::class)->text(1,str_repeat('x',9000),[]);$this->fail();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}Http::assertNotSent(fn($r)=>str_ends_with($r->url(),'/api/chat'));
 }
 public function test_routing_cannot_change_during_a_conversation_but_can_be_disabled():void {
  $this->connect();$this->newSession();try{app(AiLocalProvider::class)->save(1,$this->settings(['revision'=>1,'api_key'=>null,'text_provider'=>'openai','enabled'=>false]));$this->fail();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(409,$e->getStatusCode());}
  app(AiLocalProvider::class)->save(1,$this->settings(['revision'=>1,'api_key'=>null,'enabled'=>false]));$this->assertFalse(app(AiProvider::class)->connection(1)['text_enabled']);
 }
 public function test_unsafe_origins_are_rejected():void {
  foreach(['https://127.0.0.1','http://169.254.169.254','https://user@public.example','https://public.example/api','https://public.example?key=secret','file:///etc/passwd']as $url){try{app(AiLocalProvider::class)->target($url);$this->fail($url);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}}Http::assertNothingSent();
 }
 public function test_tenant_administrator_cannot_change_provider_connection():void {
  config(['discai_bridge_test'=>['slug'=>'example','secret'=>'fixture','required'=>false]]);
  $this->putJson('/api/ai/connection',['api_key'=>'sk-fictional-key-fixture','enabled'=>true,'daily_requests'=>10,'cost_acknowledged'=>true])->assertForbidden();
  $this->getJson('/api/ai/agents')->assertOk()->assertJsonPath('connection.can_configure',false)->assertJsonMissingPath('connection.prices');
 }
 public function test_qwen_test_qualifies_the_same_published_profile_for_whatsapp():void {
  $this->connect();$queue=DB::table('voice_live_queues')->insertGetId(['workspace_id'=>1,'name'=>'Equipe','direction'=>'inbound','agent_ids'=>'[]']);
  $c=AiAgents::defaults();$c['knowledge']=[['question'=>'Horário','answer'=>'Das 9h às 18h.']];$c['handoff_queue_id']=$queue;
  $id=$this->postJson('/api/ai/agents',['config'=>$c])->assertOk()->json('id');$this->postJson('/api/ai/agents/'.$id.'/publish',['revision'=>1])->assertOk();
  $sid=$this->postJson('/api/ai/agents/'.$id.'/start',['mode'=>'premium','cost_acknowledged'=>true])->json('id');$this->turn($sid)->assertOk();
  $sender=DB::table('wa_senders')->insertGetId(['workspace_id'=>1,'provider'=>'qr','label'=>'Fixture','number'=>'+5511000000000','ownership'=>'external']);
  $this->postJson('/api/ai/agents/'.$id.'/binding',['sender_id'=>$sender,'enabled'=>true,'cost_acknowledged'=>true])->assertOk();
  $this->assertDatabaseHas('ai_whatsapp_bindings',['agent_id'=>$id,'enabled'=>true]);$this->assertDatabaseCount('wa_messages',0);$this->assertDatabaseCount('ai_connections',0);
 }
 public function test_signed_save_is_atomic_and_does_not_activate_channels():void {
  config(['discai_bridge_test'=>['slug'=>'example','secret'=>'fixture','required'=>false]]);
  $data=['tenant'=>'example','action'=>'save','actor'=>1,'local'=>$this->settings(['enabled'=>false]),'openai'=>['enabled'=>false,'daily_requests'=>100]];
  $body=json_encode($data);$stamp=(string)time();$headers=['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_DISCAI_TIME'=>$stamp,'HTTP_X_DISCAI_SIGNATURE'=>hash_hmac('sha256',$stamp.'.discai-internal/ai.'.$body,'fixture')];
  $this->call('POST','/discai-internal/ai',[],[],[],$headers,$body)->assertOk()->assertJsonPath('local.enabled',false)->assertDontSee('fictional-private-server-key');
  $this->assertDatabaseCount('ai_whatsapp_bindings',0);$this->assertDatabaseCount('ai_sip_bindings',0);Http::assertNothingSent();
  $data['local']['revision']=1;$data['openai']['enabled']=true;$data['openai']['cost_acknowledged']=true;$body=json_encode($data);$headers['HTTP_X_DISCAI_SIGNATURE']=hash_hmac('sha256',$stamp.'.discai-internal/ai.'.$body,'fixture');
  $this->call('POST','/discai-internal/ai',[],[],[],$headers,$body)->assertUnprocessable();$this->assertSame(1,app(AiLocalProvider::class)->row(1)->revision);
 }
 public function test_html_form_numeric_strings_save_with_optimistic_revision():void {
  $d=$this->settings(['enabled'=>'0','revision'=>'0','daily_requests'=>'25','context_tokens'=>'8192','parallel_requests'=>'1','timeout_seconds'=>'35']);
  $saved=app(AiLocalProvider::class)->save(1,$d);$this->assertSame(1,$saved['revision']);$this->assertSame(25,$saved['daily_requests']);$this->assertFalse($saved['enabled']);
  $d['revision']='1';$d['api_key']=null;$saved=app(AiLocalProvider::class)->save(1,$d);$this->assertSame(2,$saved['revision']);Http::assertNothingSent();
 }
 public function test_internal_management_requires_signature_and_correct_tenant():void {
  config(['discai_bridge_test'=>['slug'=>'example','secret'=>'fixture','required'=>false]]);
  $this->postJson('/discai-internal/ai',['tenant'=>'example','action'=>'read','actor'=>1])->assertUnauthorized();
  $body=json_encode(['tenant'=>'example','action'=>'read','actor'=>1]);$stamp=(string)time();
  $this->call('POST','/discai-internal/ai',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_DISCAI_TIME'=>$stamp,'HTTP_X_DISCAI_SIGNATURE'=>hash_hmac('sha256',$stamp.'.discai-internal/ai.'.$body,'fixture')],$body)->assertOk()->assertJsonPath('local.text_provider','openai');
 }
}
