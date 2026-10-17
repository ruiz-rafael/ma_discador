<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\{AiAgents,AiProvider,AiWhatsApp,ConversationInbox};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Crypt};
use Illuminate\Support\Str;
use Tests\TestCase;
class AiAgentsTest extends TestCase {
 use RefreshDatabase;
 private User $admin;
 protected function setUp():void {parent::setUp();Http::preventStrayRequests();DB::table('voice_workspaces')->insert(['id'=>2,'name'=>'Outra empresa']);$this->admin=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin']);$this->actingAs($this->admin);}
 private function config():array{return array_replace(AiAgents::defaults(),['name'=>'Sofia','knowledge'=>[['question'=>'Qual o horário?','answer'=>'De segunda a sexta, das 9h às 18h.']]]);}
 private function agent():string{return $this->postJson('/api/ai/agents',['config'=>$this->config()])->assertOk()->json('id');}
 private function connect(int $limit=100):void {$this->putJson('/api/ai/connection',['api_key'=>'sk-fictional-key-for-tests-only','enabled'=>true,'daily_requests'=>$limit,'cost_acknowledged'=>true])->assertOk();}
 private function start(string $a,string $mode='local'):string {return $this->postJson('/api/ai/agents/'.$a.'/start',['mode'=>$mode,'cost_acknowledged'=>$mode==='premium'])->assertOk()->json('id');}
 private function response(string $reply='Posso ajudar?',string $action='reply'):array{return ['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode(['reply'=>$reply,'action'=>$action,'summary'=>'Cliente recebeu orientação.'])]]]],'usage'=>['input_tokens'=>100,'output_tokens'=>20]];}
 public function test_profile_versions_are_immutable_and_drafts_do_not_change_published_agent():void {
  $id=$this->agent();$this->postJson('/api/ai/agents/'.$id.'/publish',['revision'=>1])->assertOk()->assertJsonPath('published_version',1)->assertJsonPath('revision',2);
  $new=array_replace($this->config(),['name'=>'Sofia nova']);$this->putJson('/api/ai/agents/'.$id,['revision'=>1,'config'=>$new])->assertConflict();
  $this->putJson('/api/ai/agents/'.$id,['revision'=>2,'config'=>$new])->assertOk();$this->assertSame('Sofia',app(AiAgents::class)->published(1,$id)['name']);
  $this->assertDatabaseCount('voice_outbound_calls',0);$this->assertDatabaseCount('wa_messages',0);Http::assertNothingSent();
 }
 public function test_roles_and_workspace_boundaries():void {
  $id=$this->agent();$other=User::factory()->create(['voice_workspace_id'=>2,'voice_role'=>'admin']);$this->actingAs($other)->getJson('/api/ai/agents')->assertOk()->assertJsonCount(0,'agents');$this->postJson('/api/ai/agents/'.$id.'/publish',['revision'=>1])->assertNotFound();
  foreach(['agent','supervisor']as $role){$u=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>$role]);$this->actingAs($u)->getJson('/api/ai/agents')->assertForbidden();$this->postJson('/api/ai/agents',['config'=>$this->config()])->assertForbidden();}
 }
 public function test_local_simulation_is_explicit_free_and_idempotent():void {
  $id=$this->agent();$session=$this->start($id);$d=['request_id'=>(string)Str::uuid(),'input'=>'Qual o horário?'];$this->postJson('/api/ai/sessions/'.$session.'/turn',$d)->assertOk()->assertJsonPath('output','De segunda a sexta, das 9h às 18h.')->assertJsonPath('action','reply');$this->postJson('/api/ai/sessions/'.$session.'/turn',$d)->assertOk();$this->assertDatabaseCount('ai_turns',1);$this->postJson('/api/ai/sessions/'.$session.'/turn',array_replace($d,['input'=>'Outro texto']))->assertConflict();Http::assertNothingSent();
 }
 public function test_stop_and_human_requests_end_test_without_provider_or_contact_mutations():void {
  $id=$this->agent();foreach(['Quero um humano'=>'handoff','Pare de enviar mensagens'=>'stop']as $text=>$action){$session=$this->start($id);$this->postJson('/api/ai/sessions/'.$session.'/turn',['request_id'=>(string)Str::uuid(),'input'=>$text])->assertOk()->assertJsonPath('action',$action);$this->postJson('/api/ai/sessions/'.$session.'/turn',['request_id'=>(string)Str::uuid(),'input'=>'Oi'])->assertConflict();}Http::assertNothingSent();$this->assertDatabaseCount('voice_contacts',0);
 }
 public function test_key_is_encrypted_never_returned_and_paid_requests_require_acknowledgement():void {
  $id=$this->agent();$this->postJson('/api/ai/agents/'.$id.'/start',['mode'=>'premium'])->assertUnprocessable();$this->postJson('/api/ai/agents/'.$id.'/start',['mode'=>'premium','cost_acknowledged'=>true])->assertConflict();$this->connect();$c=DB::table('ai_connections')->first();$this->assertNotContains('sk-fictional-key-for-tests-only',[$c->credentials]);$this->assertSame('sk-fictional-key-for-tests-only',Crypt::decryptString($c->credentials));$this->getJson('/api/ai/agents')->assertDontSee('sk-fictional')->assertDontSee('credentials');Http::assertNothingSent();
 }
 public function test_premium_text_uses_server_profile_structured_output_and_tracks_usage():void {
  $id=$this->agent();$this->connect();Http::fake(['https://api.openai.com/v1/responses'=>Http::response($this->response())]);$session=$this->start($id,'premium');$this->postJson('/api/ai/sessions/'.$session.'/turn',['request_id'=>(string)Str::uuid(),'input'=>'Me conte mais'])->assertOk()->assertJsonPath('output','Posso ajudar?');Http::assertSent(fn($r)=>$r['store']===false&&$r['text']['format']['strict']===true&&str_contains($r['instructions'],'Sofia')&&$r['model']==='gpt-4.1-mini');$this->assertDatabaseHas('ai_sessions',['id'=>$session,'input_tokens'=>100,'output_tokens'=>20]);
 }
 public function test_refusal_malformed_reply_and_provider_failure_do_not_send_messages():void {
  $id=$this->agent();$this->connect();foreach([Http::response([],429),Http::response(['status'=>'incomplete']),Http::response(['status'=>'completed','output'=>[]])]as $reply){Http::fake(['https://api.openai.com/v1/responses'=>$reply]);$s=$this->start($id,'premium');$this->postJson('/api/ai/sessions/'.$s.'/turn',['request_id'=>(string)Str::uuid(),'input'=>'Dúvida'])->assertStatus(502);$this->assertDatabaseHas('ai_sessions',['id'=>$s,'status'=>'handoff']);}$this->assertDatabaseCount('wa_messages',0);
 }
 public function test_daily_quota_and_turn_limit_are_enforced():void {
  $id=$this->agent();$this->connect(1);Http::fake(['https://api.openai.com/v1/responses'=>Http::response($this->response())]);$s=$this->start($id,'premium');$this->postJson('/api/ai/sessions/'.$s.'/turn',['request_id'=>(string)Str::uuid(),'input'=>'Oi'])->assertOk();$this->postJson('/api/ai/sessions/'.$s.'/turn',['request_id'=>(string)Str::uuid(),'input'=>'Mais'])->assertStatus(429);Http::assertSentCount(1);
  $s=$this->start($id);DB::table('ai_sessions')->where('id',$s)->update(['turns'=>16]);$this->postJson('/api/ai/sessions/'.$s.'/turn',['request_id'=>(string)Str::uuid(),'input'=>'Oi'])->assertConflict();
 }
 public function test_voice_uses_server_side_sdp_negotiation_and_can_be_closed_without_telephony():void {
  $id=$this->agent();$this->connect();Http::fake(['https://api.openai.com/v1/realtime/calls'=>Http::response("v=0\r\ns=test\r\n",201,['Location'=>'https://api.openai.com/v1/realtime/calls/rtc_test']),'https://api.openai.com/v1/realtime/calls/rtc_test/hangup'=>Http::response('',200)]);
  $s=$this->postJson('/api/ai/agents/'.$id.'/voice',['sdp'=>"v=0\r\ns=offer\r\n",'cost_acknowledged'=>true])->assertOk()->json('id');$this->postJson('/api/ai/sessions/'.$s.'/close')->assertOk()->assertJsonPath('closed',true);$this->assertDatabaseHas('ai_sessions',['id'=>$s,'status'=>'closed']);$this->assertDatabaseCount('voice_outbound_calls',0);Http::assertSentCount(2);
 }
 public function test_no_foreign_session_access_and_expired_voice_is_cleaned_up():void {
  $id=$this->agent();$s=$this->start($id);$other=User::factory()->create(['voice_workspace_id'=>2,'voice_role'=>'admin']);$this->actingAs($other)->getJson('/api/ai/sessions/'.$s)->assertNotFound();$this->postJson('/api/ai/sessions/'.$s.'/close')->assertNotFound();
  $this->actingAs($this->admin);$this->connect();DB::table('ai_sessions')->where('id',$s)->update(['channel'=>'voice','provider_call_id'=>'rtc_test','expires_at'=>now()->subSecond()]);Http::fake(['https://api.openai.com/v1/realtime/calls/rtc_test/hangup'=>Http::response('',200)]);app(AiAgents::class)->sweep();$this->assertDatabaseHas('ai_sessions',['id'=>$s,'status'=>'closed']);
 }
 public function test_publish_requires_knowledge_and_invalid_queue_is_rejected():void {
  $id=$this->postJson('/api/ai/agents',['config'=>AiAgents::defaults()])->assertOk()->json('id');$this->postJson('/api/ai/agents/'.$id.'/publish',['revision'=>1])->assertUnprocessable();$c=$this->config();$c['handoff_queue_id']=999;$this->postJson('/api/ai/agents',['config'=>$c])->assertNotFound();
 }
 private function inbox(bool $enabled=true):array {
  $this->connect();$q=DB::table('voice_live_queues')->insertGetId(['workspace_id'=>1,'name'=>'Equipe','agent_ids'=>'[]','direction'=>'inbound']);$cfg=$this->config();$cfg['handoff_queue_id']=$q;$id=$this->postJson('/api/ai/agents',['config'=>$cfg])->assertOk()->json('id');$this->postJson('/api/ai/agents/'.$id.'/publish',['revision'=>1])->assertOk();$sender=DB::table('wa_senders')->insertGetId(['workspace_id'=>1,'provider'=>'qr','label'=>'Teste','number'=>'+5511000000000','ownership'=>'external']);$contact=DB::table('voice_contacts')->insertGetId(['workspace_id'=>1,'name'=>'Pessoa teste','phone'=>'+5511999990000','original_phone'=>'+5511999990000','source'=>'QA','consent'=>true,'consent_evidence'=>'Teste']);DB::table('ai_whatsapp_bindings')->insert(['workspace_id'=>1,'sender_id'=>$sender,'agent_id'=>$id,'enabled'=>$enabled,'enabled_at'=>now()->subMinute()]);config(['whatsapp_qr_test'=>['token'=>str_repeat('a',32),'url'=>'http://qr.test','allowed_recipients'=>['+5511999990000'],'daily_limit'=>10]]);return compact('id','sender','contact','q');
 }
 private function inbound(array $inbox,string $body='Olá'):string {$id=(string)Str::uuid();DB::table('wa_messages')->insert(['id'=>$id,'workspace_id'=>1,'sender_id'=>$inbox['sender'],'contact_id'=>$inbox['contact'],'direction'=>'inbound','provider'=>'qr','idempotency_key'=>$id,'request_hash'=>hash('sha256',$id),'account_sid'=>'qr:'.$inbox['sender'],'from_number'=>'+5511999990000','to_number'=>'+5511000000000','status'=>'received','body'=>$body,'created_at'=>now(),'updated_at'=>now()]);app(ConversationInbox::class)->record($id);return $id;}
 public function test_whatsapp_new_inbound_gets_one_response_and_keeps_history():void {
  $inbox=$this->inbox();Http::fake(['https://api.openai.com/v1/responses'=>Http::response($this->response('Olá! Sou o assistente virtual.')),'http://qr.test/sessions/*/messages'=>Http::response(['status'=>'sent','reference'=>'test-message']),'http://qr.test/sessions/*'=>Http::response(['status'=>'connected','number'=>'+5511000000000'])]);$id=$this->inbound($inbox);app(AiWhatsApp::class)->run($id);app(AiWhatsApp::class)->run($id);$this->assertDatabaseHas('ai_whatsapp_jobs',['message_id'=>$id,'status'=>'sent']);$this->assertSame(1,DB::table('wa_messages')->where('direction','outbound')->count());Http::assertSentCount(3);
 }
 public function test_whatsapp_human_takeover_or_disabled_binding_prevents_generation_and_delivery():void {
  $inbox=$this->inbox();$id=$this->inbound($inbox);$j=DB::table('ai_whatsapp_jobs')->first();DB::table('wa_conversations')->where('id',$j->conversation_id)->update(['assigned_user_id'=>$this->admin->id]);app(AiWhatsApp::class)->run($id);Http::assertNothingSent();$this->assertDatabaseHas('ai_whatsapp_jobs',['message_id'=>$id,'status'=>'failed']);$this->assertSame(0,DB::table('wa_messages')->where('direction','outbound')->count());
 }
 public function test_whatsapp_optout_updates_contact_without_sending_a_reply():void {
  $inbox=$this->inbox();$id=$this->inbound($inbox,'Pare de enviar mensagens');app(AiWhatsApp::class)->run($id);$this->assertDatabaseHas('ai_whatsapp_jobs',['message_id'=>$id,'status'=>'stop']);$this->assertNotNull(DB::table('voice_contacts')->find($inbox['contact'])->suppressed_at);$this->assertFalse((bool)DB::table('voice_contacts')->find($inbox['contact'])->consent);Http::assertNothingSent();
 }
 public function test_binding_does_not_replay_existing_messages_or_send_to_unapproved_recipient():void {
  $inbox=$this->inbox(false);$id=$this->inbound($inbox);$this->assertDatabaseCount('ai_whatsapp_jobs',0);DB::table('ai_whatsapp_bindings')->update(['enabled'=>true,'enabled_at'=>now()->addSecond()]);app(ConversationInbox::class)->record($id);$this->assertDatabaseCount('ai_whatsapp_jobs',0);Http::assertNothingSent();
 }
}
