<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
class DiscaiBridgeTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void{parent::setUp();Http::preventStrayRequests();config(['discai_bridge_test'=>['slug'=>'alpha','secret'=>'fixture-only','portal_url'=>'https://app.discai.ia.br','internal_url'=>'http://portal.test']]);}
 public function test_login_requires_matching_state_and_never_calls_provider():void{$this->get('/discai/sso/complete?code='.str_repeat('a',64).'&state='.str_repeat('b',64))->assertForbidden();Http::assertNothingSent();}
 public function test_successful_bridge_sets_local_role_and_preserves_local_password():void{$u=User::factory()->create(['email'=>'a@example.test']);$hash=$u->password;$state=str_repeat('b',64);Http::fake(['portal.test/*'=>Http::response(['id'=>10,'name'=>'A','email'=>$u->email,'role'=>'agent','version'=>0,'tenant'=>'alpha','limits'=>[]])]);$this->withSession(['discai_state'=>['hash'=>hash('sha256',$state),'until'=>time()+60]])->get('/discai/sso/complete?code='.str_repeat('a',64).'&state='.$state)->assertRedirect('/');$this->assertSame($hash,$u->fresh()->password);$this->assertSame('agent',$u->fresh()->voice_role);$this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.voice_role','agent');}
 public function test_cross_tenant_identity_response_is_rejected():void{Http::fake(['portal.test/*'=>Http::response(['id'=>10,'email'=>'a@example.test','role'=>'admin','version'=>0,'tenant'=>'bravo'])]);$state=str_repeat('b',64);$this->withSession(['discai_state'=>['hash'=>hash('sha256',$state),'until'=>time()+60]])->get('/discai/sso/complete?code='.str_repeat('a',64).'&state='.$state)->assertForbidden();$this->assertDatabaseCount('users',0);}
 public function test_revoked_portal_access_cannot_read_operational_api():void{$u=User::factory()->create();$u->forceFill(['voice_workspace_id'=>1,'voice_role'=>'admin'])->save();Http::fake(['portal.test/*'=>Http::response([],403)]);$this->actingAs($u)->withSession(['discai_identity'=>['id'=>20,'version'=>0,'email'=>$u->email],'voice_auth_version'=>0])->getJson('/api/bootstrap')->assertForbidden();}
 public function test_new_tenant_entry_requires_explicit_login_and_blocks_local_password_login():void {config(['discai_bridge_test.required'=>true]);$this->get('/')->assertOk()->assertSee('Entrar pela DiscAI');$this->postJson('/login',['email'=>'a@example.test','password'=>'Fixture123456'])->assertForbidden();Http::assertNothingSent();}
 public function test_provider_callbacks_use_configured_tenant_origin_not_request_host():void {config(['discai_bridge_test.required'=>true,'app.url'=>'https://alpha.app.discai.ia.br']);$this->assertSame('https://alpha.app.discai.ia.br/callbacks/twilio/voice',\App\Services\TwilioVoiceConnection::base());$this->assertSame('https://alpha.app.discai.ia.br/callbacks/twilio/whatsapp',\App\Services\TwilioWhatsAppConnection::base());$this->assertSame('https://alpha.app.discai.ia.br/callbacks/twilio/voice/inbound',\App\Services\InboundVoice::base());}
 public function test_bridge_keeps_approved_destination_and_platform_context():void {
 $this->getJson('/discai/sso/start?section=https://evil.example')->assertUnprocessable();
 $this->get('/discai/sso/start?section=queues')->assertRedirect();$this->assertSame('queues',session('discai_state.section'));
 $state=str_repeat('b',64);Http::fake(['portal.test/*'=>Http::response(['id'=>10,'name'=>'Platform','email'=>'platform@example.test','role'=>'admin','version'=>0,'tenant'=>'alpha','platform_admin'=>true,'management_url'=>'https://app.discai.ia.br/administration/1','limits'=>[]])]);
 $this->withSession(['discai_state'=>['hash'=>hash('sha256',$state),'until'=>time()+60,'section'=>'queues']])->get('/discai/sso/complete?code='.str_repeat('a',64).'&state='.$state)->assertRedirect('/#/queues');
 $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('workspace.platform_admin',true);
 }
}
