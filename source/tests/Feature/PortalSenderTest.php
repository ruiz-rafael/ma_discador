<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\Portal\{GoogleMail,Mailboxes};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Crypt,Http,Route};
class PortalSenderTest extends TestCase {
 use RefreshDatabase;
 private User $actor;
 protected function setUp():void {
  parent::setUp();foreach(glob(database_path('portal-migrations/*.php')) as $f)(require $f)->up();
  config(['discai.portal'=>true,'google_mail.client_id'=>'fixture','google_mail.client_secret'=>'fixture']);
  Route::middleware('web')->group(base_path('routes/portal.php'));
  $this->actor=User::factory()->create();DB::table('portal_operators')->insert(['user_id'=>$this->actor->id]);
  $this->actingAs($this->actor)->withSession(['voice_auth_version'=>0]);Http::preventStrayRequests();
  app(Mailboxes::class)->ensure('platform');DB::table('portal_mail_connections')->update(['email'=>'private@example.test','google_sub'=>'private','status'=>'connected','revision'=>1,'tokens'=>Crypt::encryptString(json_encode(['access_token'=>'fixture','refresh_token'=>'fixture','expires_at'=>time()+3600,'scopes'=>[GoogleMail::SEND,GoogleMail::SETTINGS]]))]);
 }
 private function provider(bool $authorized=true,int $status=200):void {
  Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();
  Http::fake(['https://gmail.googleapis.com/gmail/v1/users/me/settings/sendAs'=>Http::response(['sendAs'=>[
   ['sendAsEmail'=>'private@example.test','isPrimary'=>true],
   ['sendAsEmail'=>'commercial@example.test','verificationStatus'=>$authorized?'accepted':'pending','smtpMsa'=>['password'=>'never-expose'],'signature'=>'private data'],
   ['sendAsEmail'=>'pending@example.test','verificationStatus'=>'pending'],
  ]],$status),'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'=>Http::response(['id'=>'fixture-message'])]);
 }
 public function test_selection_is_verified_and_selected_identity_is_used_without_private_account_headers():void {
  $this->provider();$m=app(Mailboxes::class);
  $this->post('/email-administration/senders',['revision'=>1])->assertRedirect();
  $this->get('/email-administration')->assertSee('commercial@example.test')->assertDontSee('pending@example.test')->assertDontSee('never-expose');
  $this->post('/email-administration/senders',['revision'=>2,'sender'=>'commercial@example.test','sender_name'=>'DiscAI'])->assertRedirect();
  $this->assertDatabaseHas('portal_mail_connections',['sender_email'=>'commercial@example.test','enabled'=>false,'revision'=>3]);
  $m->settings('platform',$this->actor->id,3,true,20);
  $result=$m->send('platform','one',4,'customer@example.test','Convite','Mensagem');$this->assertSame('accepted',$result['status']);
  Http::assertSent(function($r){if(!str_ends_with($r->url(),'/messages/send'))return false;$raw=base64_decode(strtr($r['raw'],'-_','+/'));$this->assertStringContainsString('From: DiscAI <commercial@example.test>',$raw);$this->assertStringContainsString('Reply-To: commercial@example.test',$raw);$this->assertStringNotContainsString('private@example.test',$raw);return true;});
  $this->assertArrayNotHasKey('account',$m->status('platform'));
 }
 public function test_pending_unknown_and_stale_senders_cannot_be_saved():void {
  $this->provider();
  foreach(['pending@example.test','other@example.test'] as $email)$this->post('/email-administration/senders',['revision'=>1,'sender'=>$email])->assertStatus(422);
  $this->post('/email-administration/senders',['revision'=>0,'sender'=>'commercial@example.test'])->assertStatus(409);
  $this->post('/email-administration/senders',['revision'=>1,'sender'=>'commercial@example.test','sender_name'=>"Name\r\nBcc: attacker@example.test"])->assertSessionHasErrors('sender_name');
  $this->assertNull(DB::table('portal_mail_connections')->value('sender_email'));
 }
 public function test_revocation_and_provider_outage_block_without_fallback_or_send():void {
  $this->provider();$m=app(Mailboxes::class);$m->updateSenders('platform',$this->actor->id,1,'commercial@example.test','DiscAI');$m->settings('platform',$this->actor->id,2,true,20);
  foreach([503,200] as $status){$this->provider(false,$status);$r=$m->send('platform','blocked-'.$status,3,'customer@example.test','Hello','Body');$this->assertSame('blocked',$r['status']);Http::assertNotSent(fn($r)=>str_ends_with($r->url(),'/messages/send'));}
  $this->assertDatabaseHas('portal_mail_connections',['sender_email'=>'commercial@example.test','enabled'=>false]);
 }
 public function test_clients_cannot_read_or_edit_platform_connection_and_no_cross_tenant_sender():void {
  $tenant=DB::table('portal_tenants')->insertGetId(['slug'=>'client','name'=>'Client','status'=>'active']);DB::table('portal_memberships')->insert(['tenant_id'=>$tenant,'user_id'=>$this->actor->id,'role'=>'admin','active'=>true]);DB::table('portal_operators')->delete();
  $this->get('/email-administration')->assertForbidden();$this->post('/email-administration/senders',['revision'=>1])->assertForbidden();
  $this->get('/companies/'.$tenant.'/email')->assertOk()->assertDontSee('private@example.test');
  $this->post('/companies/999/email/senders',['revision'=>1])->assertForbidden();Http::assertNothingSent();
 }
 public function test_optional_consent_and_legacy_account_need_permission_for_aliases():void {
  $g=app(GoogleMail::class);parse_str(parse_url($g->authorization('state','verifier'),PHP_URL_QUERY),$basic);parse_str(parse_url($g->authorization('state','verifier',true),PHP_URL_QUERY),$extra);
  $this->assertStringNotContainsString(GoogleMail::SETTINGS,$basic['scope']);$this->assertStringContainsString(GoogleMail::SETTINGS,$extra['scope']);
  DB::table('portal_mail_connections')->update(['tokens'=>Crypt::encryptString(json_encode(['access_token'=>'old','expires_at'=>time()+3600]))]);
  $this->post('/email-administration/senders',['revision'=>1,'sender'=>'commercial@example.test'])->assertStatus(422);Http::assertNothingSent();
  $this->post('/email-administration/senders',['revision'=>1,'sender'=>'private@example.test'])->assertRedirect();
 }
 public function test_reconnection_clears_selected_identity_and_keeps_sending_paused():void {
  $this->provider();$m=app(Mailboxes::class);$m->updateSenders('platform',$this->actor->id,1,'commercial@example.test','DiscAI');
  $url=$m->begin('platform',$this->actor->id,'session',true);parse_str(parse_url($url,PHP_URL_QUERY),$q);
  Http::fake(['https://oauth2.googleapis.com/token'=>Http::response(['access_token'=>'new','refresh_token'=>'new','scope'=>GoogleMail::SEND.' '.GoogleMail::SETTINGS]),'https://openidconnect.googleapis.com/v1/userinfo'=>Http::response(['email'=>'other@example.test','sub'=>'other','email_verified'=>true])]);
  $m->complete($q['state'],$this->actor->id,'session','code',null);
  $this->assertDatabaseHas('portal_mail_connections',['sender_email'=>null,'sender_options'=>null,'email'=>'other@example.test','enabled'=>false]);
 }
}
