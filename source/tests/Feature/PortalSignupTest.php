<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\Portal\{Mailboxes,PlatformMail};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Crypt,Route,Hash,Mail};
class PortalSignupTest extends TestCase {
 use RefreshDatabase;
 private User $admin;
 protected function setUp():void {parent::setUp();$this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);Http::preventStrayRequests();Mail::fake();foreach(glob(database_path('portal-migrations/*.php'))as $m)(require $m)->up();config(['discai.portal'=>true,'discai.portal_url'=>'https://app.discai.ia.br','mail.default'=>'log']);Route::middleware('web')->group(base_path('routes/portal.php'));$this->admin=User::factory()->create();DB::table('portal_operators')->insert(['user_id'=>$this->admin->id]);$this->actingAs($this->admin)->withSession(['voice_auth_version'=>0]);}
 private function input():array{return ['email'=>'new@example.test','company_name'=>'Exemplo','plan'=>'Inicial','seats'=>3,'channels'=>2,'storage_mb'=>1024,'delivery'=>'link'];}
 private function invite(array $extra=[]):string{$this->post('/signup-invitations',$extra+$this->input())->assertRedirect()->assertSessionHas('invitation');return parse_url(session('invitation'),PHP_URL_PATH);}
 private function registration():array{return ['name'=>'Pessoa convidada','company_name'=>'Minha empresa','slug'=>'minha-empresa','password'=>'NewPassword123456','password_confirmation'=>'NewPassword123456'];}
 public function test_new_tenant_invitation_is_admin_only_and_get_does_not_consume():void {
  $this->get('/signup-invitations')->assertOk()->assertSee('Convidar');$path=$this->invite();$this->get($path)->assertOk()->assertSee('Sua próxima conversa');$this->assertDatabaseMissing('portal_signup_invitations',['accepted_at'=>now()]);$this->assertDatabaseCount('portal_tenants',0);Mail::assertNothingSent();Http::assertNothingSent();
  DB::table('portal_operators')->delete();$this->get('/signup-invitations')->assertForbidden();$this->post('/signup-invitations',$this->input())->assertForbidden();$this->get($path)->assertForbidden();
 }
 public function test_signup_consumes_token_creates_only_tenant_admin_and_preserves_invited_limits():void {
  $path=$this->invite();$this->post($path,$this->registration()+['email'=>'attacker@example.test','role'=>'operator','seats'=>100,'channels'=>100])->assertRedirect('/companies');$t=DB::table('portal_tenants')->first();$this->assertSame('provisioning',$t->status);$this->assertSame(3,$t->seats);$this->assertSame(2,$t->channels);$u=User::where('email','new@example.test')->firstOrFail();$this->assertTrue(Hash::check('NewPassword123456',$u->password));$this->assertDatabaseHas('portal_memberships',['tenant_id'=>$t->id,'user_id'=>$u->id,'role'=>'admin','active'=>true]);$this->assertDatabaseMissing('portal_operators',['user_id'=>$u->id]);$this->assertNull(DB::table('portal_signup_invitations')->value('token_encrypted'));$this->get($path)->assertStatus(410);$this->post($path,$this->registration())->assertStatus(410);$this->assertDatabaseCount('portal_tenants',1);Http::assertNothingSent();Mail::assertNothingSent();
 }
 public function test_existing_user_requires_current_password_and_is_not_overwritten():void {
  $u=User::factory()->create(['email'=>'new@example.test','password'=>'ExistingPassword123']);$path=$this->invite();$this->postJson($path,$this->registration())->assertUnprocessable();$this->assertTrue(Hash::check('ExistingPassword123',$u->fresh()->password));$this->assertDatabaseCount('portal_tenants',0);
  $this->post($path,array_replace($this->registration(),['password'=>'ExistingPassword123','password_confirmation'=>'ExistingPassword123']))->assertRedirect('/companies');$this->assertDatabaseHas('portal_memberships',['user_id'=>$u->id,'role'=>'admin']);
 }
 public function test_expiry_revocation_duplicate_and_slug_conflict():void {
  $path=$this->invite();DB::table('portal_signup_invitations')->update(['expires_at'=>now()->subSecond()]);$this->get($path)->assertStatus(410);
  $path=$this->invite();$id=DB::table('portal_signup_invitations')->whereNull('revoked_at')->value('id');$this->post('/signup-invitations/'.$id.'/revoke')->assertRedirect();$this->get($path)->assertStatus(410);
  $old=$this->invite();$path=$this->invite();$this->get($old)->assertStatus(410);DB::table('portal_tenants')->insert(['name'=>'Existing','slug'=>'minha-empresa','status'=>'provisioning']);$this->postJson($path,$this->registration())->assertUnprocessable();$this->assertNull(DB::table('portal_signup_invitations')->whereNull('revoked_at')->value('accepted_at'));$this->assertDatabaseMissing('users',['email'=>'new@example.test']);
 }
 public function test_missing_mail_configuration_blocks_email_but_links_work():void {
  $this->postJson('/signup-invitations',array_replace($this->input(),['delivery'=>'email']))->assertUnprocessable();$this->assertDatabaseCount('portal_signup_invitations',0);$path=$this->invite();$this->get($path)->assertOk();Mail::assertNothingSent();
 }
 public function test_email_uses_platform_mail_with_button_and_prevents_duplicate_submissions():void {
  $mock=\Mockery::mock(PlatformMail::class);$mock->shouldReceive('ready')->andReturn(true);$mock->shouldReceive('send')->once()->withArgs(function($key,$to,$subject,$text,$html){return str_starts_with($key,'signup:')&&$to==='new@example.test'&&str_contains($html,'Criar minha empresa na DiscAI')&&str_contains($html,'https://app.discai.ia.br/register/company/');});$this->app->instance(PlatformMail::class,$mock);
  $this->post('/signup-invitations',array_replace($this->input(),['delivery'=>'email']))->assertRedirect();$i=DB::table('portal_signup_invitations')->first();$this->assertSame('accepted',$i->delivery_status);$this->post('/signup-invitations/'.$i->id.'/send')->assertStatus(409);$this->assertStringNotContainsString(Crypt::decryptString($i->token_encrypted),DB::table('portal_audit')->get()->toJson());
 }
}
