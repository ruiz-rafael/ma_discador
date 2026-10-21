<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Http\Middleware\RecoverExpiredLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Route,Http,Cache};
class PortalLoginSessionTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void {
  parent::setUp();Http::preventStrayRequests();Cache::flush();
  foreach(glob(database_path('portal-migrations/*.php')) as $migration)(require $migration)->up();
  config(['discai.portal'=>true]);Route::middleware('web')->group(base_path('routes/portal.php'));
  // Exercise actual CSRF validation instead of Laravel's normal test bypass.
  $this->app->bind(RecoverExpiredLogin::class,fn($app)=>new class($app,$app['encrypter']) extends RecoverExpiredLogin {
   protected function runningUnitTests(){return false;}
  });
 }
 public function test_expired_login_redirects_without_authenticating_or_flashing_password():void {
  $u=User::factory()->create(['email'=>'member@example.test','password'=>'CorrectPassword123']);
  $this->withSession(['_token'=>str_repeat('a',40)])
   ->post('/login',['email'=>$u->email,'password'=>'CorrectPassword123','_token'=>str_repeat('b',40),'next'=>'https://evil.example'])
   ->assertStatus(303)->assertRedirect('/login')->assertSessionHasErrors('login')
   ->assertSessionHas('_old_input',['email'=>$u->email]);
  $this->assertGuest();$this->assertDatabaseCount('portal_audit',0);
  $this->get('/login')->assertOk()->assertSee('Digite sua senha novamente')->assertDontSee('CorrectPassword123')->assertDontSee('evil.example');
 }
 public function test_current_token_authenticates_client_and_routes_only_to_its_tenant():void {
  $u=User::factory()->create(['email'=>'member@example.test','password'=>'CorrectPassword123']);
  $tid=DB::table('portal_tenants')->insertGetId(['slug'=>'bossanova','name'=>'Bossa Nova','status'=>'active']);
  DB::table('portal_memberships')->insert(['tenant_id'=>$tid,'user_id'=>$u->id,'role'=>'admin','active'=>true]);
  $r=$this->getJson('/login/session')->assertOk()->assertHeader('Cache-Control','no-store, private')->assertHeader('Vary','Cookie, Accept');
  $token=$r->json('token');$this->assertSame(40,strlen($token));
  $this->post('/login',['email'=>$u->email,'password'=>'CorrectPassword123','_token'=>$token])->assertRedirect('/t/bossanova');
  $this->assertAuthenticatedAs($u);$this->assertDatabaseHas('portal_audit',['event'=>'login','user_id'=>$u->id]);
 }
 public function test_navigation_to_session_endpoint_returns_to_login_instead_of_exposing_json():void {
  $this->get('/login/session')->assertRedirect('/login')->assertHeader('Cache-Control','no-store, private');
 }
 public function test_invalid_credentials_after_background_refresh_return_to_usable_login():void {
  // Legacy clients updated Laravel's previous URL with this JSON GET.
  $token=$this->getJson('/login/session')->assertOk()->json('token');
  $this->post('/login',['email'=>'missing@example.test','password'=>'DoNotRetainPassword','_token'=>$token])
   ->assertRedirect('/login')->assertSessionHasErrors('login')->assertSessionHas('_old_input',['email'=>'missing@example.test']);
  $this->assertGuest();
  $this->get('/login')->assertOk()->assertSee('E-mail ou senha inválidos.')->assertDontSee('DoNotRetainPassword');
 }
 public function test_background_refresh_does_not_replace_previous_page():void {
  $this->get('/login')->assertOk();$previous=session()->previousUrl();
  $this->getJson('/login/session',['X-Requested-With'=>'XMLHttpRequest'])->assertOk();
  $this->assertSame($previous,session()->previousUrl());
 }
 public function test_invalid_csrf_stays_rejected_for_json_and_other_actions():void {
  $this->postJson('/login',['email'=>'x@example.test','password'=>'secret'])->assertStatus(419);
  $this->post('/administration',['name'=>'Not created'])->assertStatus(419);
  $this->assertDatabaseCount('portal_tenants',0);
  // Existing signed callbacks retain their intentional CSRF exemption and own authorization.
  $this->postJson('/portal-internal/check',['tenant'=>'unknown','user_id'=>1])->assertUnauthorized();
 }
 public function test_stale_login_does_not_sign_out_an_existing_authenticated_user():void {
  $u=User::factory()->create();$this->actingAs($u)->withSession(['_token'=>str_repeat('c',40),'voice_auth_version'=>0]);
  $this->post('/login',['email'=>'another@example.test','password'=>'secret','_token'=>'stale'])->assertStatus(303);
  $this->assertAuthenticatedAs($u);$this->assertSame(0,$u->fresh()->voice_auth_version);
 }
 public function test_recovery_does_not_change_tenant_local_login_csrf_rules():void {
  config(['discai.portal'=>false]);$this->post('/login',['email'=>'x@example.test','password'=>'secret'])->assertStatus(419);
 }
}
