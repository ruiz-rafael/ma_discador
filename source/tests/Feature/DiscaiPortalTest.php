<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\Portal\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Crypt,Hash,Http};
class DiscaiPortalTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void {parent::setUp();Http::preventStrayRequests();foreach(glob(database_path('portal-migrations/*.php')) as $migration)(require $migration)->up();config(['discai.portal'=>true]);\Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/portal.php'));}
 private function tenant(string $slug='alpha',string $status='active'):int{return DB::table('portal_tenants')->insertGetId(['slug'=>$slug,'name'=>ucfirst($slug),'status'=>$status,'url'=>'https://'.$slug.'.app.discai.ia.br','secret'=>Crypt::encryptString(str_repeat($slug,16)),'created_at'=>now(),'updated_at'=>now()]);}
 private function member(int $t,User $u,string $role='agent'):void{DB::table('portal_memberships')->insert(['tenant_id'=>$t,'user_id'=>$u->id,'role'=>$role,'active'=>true]);}
 private function signed(string $path,array $data,string $secret){$body=json_encode($data);$stamp=(string)time();return $this->call('POST',$path,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_DISCAI_TIME'=>$stamp,'HTTP_X_DISCAI_SIGNATURE'=>hash_hmac('sha256',$stamp.'.'.ltrim($path,'/').'.'.$body,$secret)],$body);}
 public function test_company_selection_does_not_expose_other_tenants():void{$u=User::factory()->create();$a=$this->tenant();$b=$this->tenant('bravo');$this->member($a,$u);$this->actingAs($u)->withSession(['voice_auth_version'=>0])->get('/companies')->assertOk()->assertSee('Alpha')->assertDontSee('Bravo');$this->get('/t/bravo')->assertForbidden();$this->get('/administration')->assertForbidden();}
 public function test_multiple_memberships_have_independent_roles():void{$u=User::factory()->create();$a=$this->tenant();$b=$this->tenant('bravo');$this->member($a,$u,'admin');$this->member($b,$u);$s=app(Access::class);$this->assertSame('admin',$s->identity($a,$u->id)['role']);$this->assertSame('agent',$s->identity($b,$u->id)['role']);}
 public function test_grant_is_one_use_bound_to_tenant_state_and_active_membership():void{$u=User::factory()->create();$a=$this->tenant();$this->tenant('bravo');$this->member($a,$u);$token=str_repeat('a',64);$state=str_repeat('b',64);DB::table('portal_grants')->insert(['tenant_id'=>$a,'user_id'=>$u->id,'token_hash'=>hash('sha256',$token),'state_hash'=>hash('sha256',$state),'expires_at'=>now()->addMinute()]);$data=['tenant'=>'alpha','code'=>$token,'state'=>$state];$this->signed('/portal-internal/redeem',array_replace($data,['tenant'=>'bravo']),str_repeat('bravo',16))->assertForbidden();$this->signed('/portal-internal/redeem',array_replace($data,['state'=>str_repeat('c',64)]),str_repeat('alpha',16))->assertForbidden();$this->signed('/portal-internal/redeem',$data,'incorrect')->assertUnauthorized();$this->signed('/portal-internal/redeem',$data,str_repeat('alpha',16))->assertOk()->assertJsonPath('email',$u->email);$this->signed('/portal-internal/redeem',$data,str_repeat('alpha',16))->assertForbidden();}
 public function test_suspension_and_revocation_apply_to_session_checks():void{$u=User::factory()->create();$a=$this->tenant();$this->member($a,$u);$data=['tenant'=>'alpha','user_id'=>$u->id];$this->signed('/portal-internal/check',$data,str_repeat('alpha',16))->assertOk();DB::table('portal_memberships')->where('tenant_id',$a)->update(['active'=>false]);$this->signed('/portal-internal/check',$data,str_repeat('alpha',16))->assertForbidden();DB::table('portal_memberships')->where('tenant_id',$a)->update(['active'=>true]);DB::table('portal_tenants')->where('id',$a)->update(['status'=>'suspended']);$this->signed('/portal-internal/check',$data,str_repeat('alpha',16))->assertForbidden();}
 public function test_operator_can_oversee_all_tenants_without_consuming_seats_and_access_is_audited():void{
 $u=User::factory()->create();DB::table('portal_operators')->insert(['user_id'=>$u->id]);$t=$this->tenant();$this->tenant('bravo');
 $this->actingAs($u)->withSession(['voice_auth_version'=>0])->get('/companies')->assertOk()->assertSee('Alpha')->assertSee('Bravo')->assertSee('administrador geral');
 $this->get('/administration')->assertOk();$this->get('/administration/'.$t)->assertOk();
 $this->get('/t/alpha?section=queues')->assertRedirect('https://alpha.app.discai.ia.br/discai/sso/start?section=queues');
 $this->assertDatabaseHas('portal_audit',['event'=>'platform.operation_opened','user_id'=>$u->id,'tenant_id'=>$t]);$this->assertDatabaseCount('portal_memberships',0);
 $this->signed('/portal-internal/check',['tenant'=>'alpha','user_id'=>$u->id],str_repeat('alpha',16))->assertOk()->assertJsonPath('role','admin')->assertJsonPath('platform_admin',true);
 DB::table('portal_operators')->delete();$this->get('/t/alpha')->assertForbidden();
}
 public function test_supervisor_has_operational_access_but_cannot_administrate_or_invite():void{
 $u=User::factory()->create();$a=$this->tenant();$this->tenant('bravo');$this->member($a,$u,'supervisor');
 $this->actingAs($u)->withSession(['voice_auth_version'=>0])->get('/companies')->assertOk()->assertSee('Supervisão')->assertDontSee('Equipe e convites')->assertDontSee('Bravo');
 $this->get('/t/alpha?section=supervision')->assertRedirect();$this->get('/administration/'.$a)->assertForbidden();
 foreach(['admin','supervisor','agent'] as $role)$this->postJson('/administration/'.$a.'/invitations',['email'=>'person@example.test','role'=>$role])->assertForbidden();
 $this->postJson('/administration/'.$a.'/members/1',['role'=>'admin','active'=>true])->assertForbidden();$this->assertDatabaseCount('portal_invitations',0);
}
 public function test_admin_link_invite_acceptance_cancel_and_issuer_revocation():void{
 \Illuminate\Support\Facades\Mail::fake();$u=User::factory()->create();$a=$this->tenant();$b=$this->tenant('bravo');$this->member($a,$u,'admin');
 $this->actingAs($u)->withSession(['voice_auth_version'=>0])->post('/administration/'.$a.'/invitations',['email'=>'supervisor@example.test','role'=>'supervisor'])->assertRedirect()->assertSessionHas('invitation');
 $url=session('invitation');$id=DB::table('portal_invitations')->value('id');$this->assertDatabaseHas('portal_invitations',['id'=>$id,'role'=>'supervisor','created_by'=>$u->id,'delivery_status'=>'link']);
 \Illuminate\Support\Facades\Mail::assertNothingSent();$this->postJson('/administration/'.$b.'/invitations/'.$id.'/revoke')->assertForbidden();
 $this->post('/administration/'.$a.'/invitations/'.$id.'/revoke')->assertRedirect();$this->postJson(parse_url($url,PHP_URL_PATH),['name'=>'Supervisor','password'=>'InternalPassword123'])->assertStatus(410);
 $this->post('/administration/'.$a.'/invitations',['email'=>'newadmin@example.test','role'=>'admin'])->assertRedirect();$url=session('invitation');
 DB::table('portal_memberships')->where('user_id',$u->id)->update(['role'=>'supervisor']);
 $this->postJson(parse_url($url,PHP_URL_PATH),['name'=>'New admin','password'=>'InternalPassword123'])->assertForbidden();
}
 public function test_invitation_email_requires_configured_transport_and_seats_are_reserved():void{
 \Illuminate\Support\Facades\Mail::fake();$u=User::factory()->create();$a=$this->tenant();$this->member($a,$u,'admin');DB::table('portal_tenants')->where('id',$a)->update(['seats'=>2]);
 $this->actingAs($u)->withSession(['voice_auth_version'=>0]);config(['mail.default'=>'log']);
 $this->postJson('/administration/'.$a.'/invitations',['email'=>'new@example.test','role'=>'agent','delivery'=>'email'])->assertUnprocessable();$this->assertDatabaseCount('portal_invitations',0);
 config(['mail.default'=>'smtp','mail.mailers.smtp.host'=>'smtp.example.test','mail.from.address'=>'invite@discai.example.test']);
 $this->post('/administration/'.$a.'/invitations',['email'=>'new@example.test','role'=>'agent','delivery'=>'email'])->assertRedirect();\Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PortalInvitation::class);
 $this->postJson('/administration/'.$a.'/invitations',['email'=>'overflow@example.test','role'=>'agent'])->assertUnprocessable();
 $this->post('/administration/'.$a.'/invitations',['email'=>'new@example.test','role'=>'supervisor'])->assertRedirect();$this->assertSame(1,DB::table('portal_invitations')->where('expires_at','>',now())->count());
}
 public function test_login_stays_on_company_selector_for_global_admin():void{
 $u=User::factory()->create(['email'=>'platform@example.test','password'=>'InternalPassword123']);DB::table('portal_operators')->insert(['user_id'=>$u->id]);$t=$this->tenant();$this->member($t,$u,'admin');
 $this->post('/login',['email'=>$u->email,'password'=>'InternalPassword123'])->assertRedirect('/companies');
}
 public function test_internal_checks_do_not_consume_login_allowance_and_login_remains_limited():void {
  $t=$this->tenant();$u=User::factory()->create();$this->member($t,$u);
  for($i=0;$i<8;$i++)$this->signed('/portal-internal/check',['tenant'=>'alpha','user_id'=>$u->id],str_repeat('alpha',16))->assertOk();
  for($i=0;$i<5;$i++)$this->post('/login',['email'=>'nobody@example.test','password'=>'InvalidPassword123'])->assertRedirect()->assertSessionHasErrors('login');
  $this->post('/login',['email'=>'nobody@example.test','password'=>'InvalidPassword123'])->assertStatus(429);
 }

 public function test_invitation_cannot_take_over_existing_account_and_seat_limit_is_enforced():void{$u=User::factory()->create(['email'=>'existing@example.test','password'=>'OldPass9']);$t=$this->tenant();$token=str_repeat('f',64);DB::table('portal_invitations')->insert(['tenant_id'=>$t,'email'=>$u->email,'role'=>'agent','token_hash'=>hash('sha256',$token),'expires_at'=>now()->addHour()]);$this->postJson('/invite/'.$token,['name'=>'Name','password'=>'AttackerPassword123'])->assertUnprocessable();$this->assertTrue(Hash::check('OldPass9',$u->fresh()->password));DB::table('portal_tenants')->where('id',$t)->update(['seats'=>0]);$this->postJson('/invite/'.$token,['name'=>'Name','password'=>'OldPass9'])->assertUnprocessable();$this->assertDatabaseCount('portal_memberships',0);DB::table('portal_tenants')->where('id',$t)->update(['seats'=>3]);$this->post('/invite/'.$token,['name'=>'Name','password'=>'OldPass9'])->assertRedirect('/companies');}
 public function test_valid_invite_creates_only_its_membership_and_cannot_be_reused():void{$t=$this->tenant();$token=str_repeat('c',64);DB::table('portal_invitations')->insert(['tenant_id'=>$t,'email'=>'new@example.test','role'=>'agent','token_hash'=>hash('sha256',$token),'expires_at'=>now()->addHour()]);$this->post('/invite/'.$token,['name'=>'New person','password'=>'SafePassword12345'])->assertRedirect('/companies');$this->assertDatabaseHas('portal_memberships',['tenant_id'=>$t,'role'=>'agent']);$this->postJson('/invite/'.$token,['name'=>'New person','password'=>'SafePassword12345'])->assertStatus(410);}
 public function test_expired_grants_and_password_session_version_are_rejected():void{$u=User::factory()->create();$t=$this->tenant();$this->member($t,$u);DB::table('portal_grants')->insert(['tenant_id'=>$t,'user_id'=>$u->id,'token_hash'=>hash('sha256',str_repeat('a',64)),'state_hash'=>hash('sha256',str_repeat('b',64)),'expires_at'=>now()->subSecond()]);$this->signed('/portal-internal/redeem',['tenant'=>'alpha','code'=>str_repeat('a',64),'state'=>str_repeat('b',64)],str_repeat('alpha',16))->assertForbidden();$this->actingAs($u)->withSession(['voice_auth_version'=>-1])->get('/companies')->assertRedirect('/login');}
 public function test_pending_tenant_cannot_be_opened():void{$u=User::factory()->create();$t=$this->tenant('alpha','provisioning');$this->member($t,$u);$this->actingAs($u)->withSession(['voice_auth_version'=>0])->get('/t/alpha')->assertForbidden();}
 public function test_company_admin_can_manage_own_team_but_not_another_company():void {$u=User::factory()->create();$a=$this->tenant();$b=$this->tenant('bravo');$this->member($a,$u,'admin');$this->actingAs($u)->withSession(['voice_auth_version'=>0])->get('/administration/'.$a)->assertOk();$this->get('/administration/'.$b)->assertForbidden();$this->postJson('/administration/'.$b.'/invitations',['email'=>'person@example.test','role'=>'admin'])->assertForbidden();$this->postJson('/administration/'.$a,['name'=>'Changed','plan'=>'Large','seats'=>100,'channels'=>10,'storage_mb'=>1024,'status'=>'active'])->assertForbidden();}
 public function test_logout_revokes_other_portal_sessions_by_version():void {$u=User::factory()->create();$this->actingAs($u)->withSession(['voice_auth_version'=>0])->post('/logout')->assertRedirect('/login');$this->assertSame(1,$u->fresh()->voice_auth_version);}
}
