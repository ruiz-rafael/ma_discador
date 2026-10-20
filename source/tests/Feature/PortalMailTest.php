<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\Portal\{Mailboxes,GoogleMail,PlatformMail};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Crypt,Route};
class PortalMailTest extends TestCase {
 use RefreshDatabase;
 private int $tenant; private User $user;
 protected function setUp():void {parent::setUp();Http::preventStrayRequests();foreach(glob(database_path('portal-migrations/*.php'))as $m)(require $m)->up();config(['discai.portal'=>true,'discai.portal_url'=>'https://app.discai.ia.br','google_mail.client_id'=>'fixture-client','google_mail.client_secret'=>'fixture-secret']);Route::middleware('web')->group(base_path('routes/portal.php'));$this->tenant=DB::table('portal_tenants')->insertGetId(['slug'=>'example','name'=>'Empresa exemplo','status'=>'active','url'=>'https://tenant.example','secret'=>Crypt::encryptString('fixture-secret')]);$this->user=User::factory()->create();$this->actingAs($this->user)->withSession(['voice_auth_version'=>0]); }
 private function operator():void {DB::table('portal_operators')->insert(['user_id'=>$this->user->id]);}
 private function member(string $role='admin'):void {DB::table('portal_memberships')->insert(['tenant_id'=>$this->tenant,'user_id'=>$this->user->id,'role'=>$role,'active'=>true]);}
 private function connect(string $scope='platform',int $limit=20): void {app(Mailboxes::class)->ensure($scope);DB::table('portal_mail_connections')->where('scope',$scope)->update(['status'=>'connected','enabled'=>true,'revision'=>1,'daily_limit'=>$limit,'sender_email'=>$scope==='platform'?'commercial@discai.example':'tenant@company.example','email'=>$scope==='platform'?'commercial@discai.example':'tenant@company.example','google_sub'=>$scope,'tokens'=>Crypt::encryptString(json_encode(['access_token'=>'private-access','refresh_token'=>'private-refresh','expires_at'=>time()+3600]))]);}
 private function fakeGoogle(string $sub='google-sub'):void {Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();Http::fake(['https://oauth2.googleapis.com/token'=>Http::response(['access_token'=>'private-access','refresh_token'=>'private-refresh','expires_in'=>3600,'scope'=>GoogleMail::SEND]),'https://openidconnect.googleapis.com/v1/userinfo'=>Http::response(['sub'=>$sub,'email'=>'commercial@discai.example','email_verified'=>true]),'https://gmail.googleapis.com/*'=>Http::response(['id'=>'mock-message'])]);}
 private function state(string $scope='platform',string $session='session-a'):string {$url=app(Mailboxes::class)->begin($scope,$this->user->id,$session);parse_str(parse_url($url,PHP_URL_QUERY),$q);$this->assertSame('S256',$q['code_challenge_method']);$this->assertSame('https://app.discai.ia.br/oauth/google/callback',$q['redirect_uri']);$this->assertStringNotContainsString('gmail.readonly',$q['scope']);return $q['state'];}
 private function rejects(callable $f,int $status):void {try{$f();$this->fail('Expected rejection');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame($status,$e->getStatusCode());}}
 public function test_permission_boundaries_and_pages():void {
  $this->member();$this->get('/companies/'.$this->tenant.'/email')->assertOk()->assertSee('Empresa exemplo');$this->get('/email-administration')->assertForbidden();$this->get('/companies/999/email')->assertForbidden();$this->post('/email-administration/connect')->assertForbidden();
  DB::table('portal_memberships')->update(['role'=>'supervisor']);$this->get('/companies/'.$this->tenant.'/email')->assertForbidden();$this->operator();$this->get('/email-administration')->assertOk()->assertSee('DiscAI');$this->assertDatabaseCount('portal_mail_connections',0);Http::assertNothingSent();
 }
 public function test_oauth_is_single_use_session_bound_and_secrets_encrypted():void {
  $this->operator();$this->fakeGoogle();$state=$this->state();$m=app(Mailboxes::class);
  $this->rejects(fn()=>$m->complete($state,$this->user->id,'wrong','code',null),403);Http::assertNothingSent();
  $this->assertSame('platform',$m->complete($state,$this->user->id,'session-a','code',null));$c=DB::table('portal_mail_connections')->first();$this->assertFalse((bool)$c->enabled);$this->assertStringNotContainsString('private-refresh',$c->tokens);$this->assertStringContainsString('private-refresh',Crypt::decryptString($c->tokens));$this->assertFalse($m->status('platform')['ready']);$this->get('/email-administration')->assertOk()->assertDontSee('private-refresh')->assertDontSee('private-access');
  $this->rejects(fn()=>$m->complete($state,$this->user->id,'session-a','code',null),403);Http::assertSentCount(2);
 }
 public function test_oauth_expiry_user_revocation_and_revision_are_enforced():void {
  $this->operator();$s=$this->state();DB::table('portal_mail_oauth_states')->update(['expires_at'=>now()->subSecond()]);$this->rejects(fn()=>app(Mailboxes::class)->complete($s,$this->user->id,'session-a','code',null),403);
  $s=$this->state();DB::table('portal_operators')->delete();$this->rejects(fn()=>app(Mailboxes::class)->complete($s,$this->user->id,'session-a','code',null),403);Http::assertNothingSent();
  $this->operator();$this->fakeGoogle();$s=$this->state();DB::table('portal_mail_connections')->update(['revision'=>2]);$this->rejects(fn()=>app(Mailboxes::class)->complete($s,$this->user->id,'session-a','code',null),409);$this->assertDatabaseHas('portal_mail_connections',['status'=>'disconnected']);
 }
 public function test_missing_scope_and_duplicate_account_do_not_replace_connections():void {
  $this->operator();$this->connect();$s=$this->state();Http::fake(['*'=>Http::response(['access_token'=>'secret','refresh_token'=>'secret','scope'=>'openid'])]);try{app(Mailboxes::class)->complete($s,$this->user->id,'session-a','code',null);$this->fail();}catch(\RuntimeException $e){$this->assertStringContainsString('permissões',$e->getMessage());}$this->assertDatabaseHas('portal_mail_connections',['scope'=>'platform','email'=>'commercial@discai.example','enabled'=>true,'revision'=>1]);
  $this->connect('tenant:'.$this->tenant);$s=$this->state();$this->fakeGoogle('tenant:'.$this->tenant);$this->rejects(fn()=>app(Mailboxes::class)->complete($s,$this->user->id,'session-a','code',null),422);
 }
 public function test_sending_isolated_idempotent_and_capped():void {
  $this->connect('platform',1);$this->connect('tenant:'.$this->tenant);$this->fakeGoogle();$m=app(Mailboxes::class);
  $r=$m->send('platform','key',1,'recipient@example.test','Assunto','Olá');$this->assertSame('accepted',$r['status']);$this->assertSame($r,$m->send('platform','key',1,'recipient@example.test','Assunto','Olá'));Http::assertSentCount(1);
  $this->rejects(fn()=>$m->send('platform','key',1,'different@example.test','Assunto','Olá'),409);$this->rejects(fn()=>$m->send('platform','other',1,'recipient@example.test','Assunto','Olá'),429);
  $m->send('tenant:'.$this->tenant,'key',1,'recipient@example.test','Assunto','Olá');Http::assertSentCount(2);$froms=[];foreach(Http::recorded()as[$req,$res]){$raw=base64_decode(strtr($req['raw'],'-_','+/'));$froms[]=$raw;} $this->assertStringContainsString('From: commercial@discai.example',$froms[0]);$this->assertStringContainsString('From: tenant@company.example',$froms[1]);
 }
 public function test_ambiguous_send_is_not_retried_and_invalid_grant_requires_reconnect():void {
  $this->connect();Http::fake(['*'=>Http::response(['error'=>'upstream-secret'],503)]);$m=app(Mailboxes::class);$r=$m->send('platform','key',1,'r@example.test','Subject','Text');$this->assertSame('unknown',$r['status']);$this->assertSame($r,$m->send('platform','key',1,'r@example.test','Subject','Text'));Http::assertSentCount(1);$this->assertStringNotContainsString('upstream-secret',DB::table('portal_mail_submissions')->get()->toJson());
  DB::table('portal_mail_connections')->update(['tokens'=>Crypt::encryptString(json_encode(['access_token'=>'old','refresh_token'=>'invalid','expires_at'=>0]))]);Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();Http::fake(['*'=>Http::response(['error'=>'invalid_grant'],400)]);$r=$m->send('platform','second',1,'r@example.test','Subject','Text');$this->assertSame('blocked',$r['status']);$this->assertDatabaseHas('portal_mail_connections',['status'=>'reconnect','enabled'=>false]);
 }
 public function test_disconnection_never_falls_back_to_smtp():void {
  $this->operator();$this->connect();config(['mail.default'=>'smtp','mail.mailers.smtp.host'=>'smtp.example.test','mail.from.address'=>'other@example.test']);app(Mailboxes::class)->disconnect('platform',$this->user->id,1);$this->assertFalse(app(PlatformMail::class)->ready());$this->assertNull(DB::table('portal_mail_connections')->value('tokens'));$this->rejects(fn()=>app(Mailboxes::class)->send('platform','k',1,'r@example.test','Subject','Text'),422);Http::assertNothingSent();
 }
 private function signed(array $data,string $secret='fixture-secret',string $action='status'){ $path='/portal-internal/mail/'.$action;$body=json_encode($data);$stamp=(string)time();return $this->call('POST',$path,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_DISCAI_TIME'=>$stamp,'HTTP_X_DISCAI_SIGNATURE'=>hash_hmac('sha256',$stamp.'.'.ltrim($path,'/').'.'.$body,$secret)],$body); }
 public function test_signed_endpoint_ignores_spoofed_scope_sender_and_rejects_bad_signatures():void {
  $this->connect('tenant:'.$this->tenant);$this->connect();$this->fakeGoogle();$this->signed(['tenant'=>'example'],'wrong')->assertUnauthorized();$this->signed(['tenant'=>'example','scope'=>'platform'])->assertOk()->assertJsonPath('from','tenant@company.example')->assertJsonMissing(['tokens'=>'private-access']);
  $d=['tenant'=>'example','scope'=>'platform','from'=>'fake@example.test','request_key'=>(string)\Illuminate\Support\Str::uuid(),'revision'=>1,'to'=>'r@example.test','subject'=>'Subject','body'=>'Text'];$this->signed($d,'fixture-secret','send')->assertOk()->assertJsonPath('status','accepted');$this->assertDatabaseHas('portal_mail_submissions',['scope'=>'tenant:'.$this->tenant]);$this->assertDatabaseMissing('portal_mail_submissions',['scope'=>'platform']);
  DB::table('portal_tenants')->update(['status'=>'suspended']);$this->signed($d)->assertForbidden();Http::assertSentCount(1);
 }
}
