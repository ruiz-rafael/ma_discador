<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\Portal\AdministrativeDialer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Crypt,Route};
use Illuminate\Support\Str;
class PortalCallingTest extends TestCase {
 use RefreshDatabase;
 private User $user;private int $tenant;
 protected function setUp():void {parent::setUp();Http::preventStrayRequests();foreach(glob(database_path('portal-migrations/*.php'))as $m)(require $m)->up();config(['discai.portal'=>true]);Route::middleware('web')->group(base_path('routes/portal.php'));$this->withoutVite();$this->user=User::factory()->create();$this->actingAs($this->user)->withSession(['voice_auth_version'=>0]);$this->tenant=DB::table('portal_tenants')->insertGetId(['slug'=>'example','name'=>'Example','status'=>'active','url'=>'https://tenant.example','secret'=>Crypt::encryptString('secret')]);}
 private function operator():void {DB::table('portal_operators')->insert(['user_id'=>$this->user->id]);}
 private function route():string{return Crypt::encryptString(json_encode(['tenant'=>$this->tenant,'user'=>$this->user->id,'number'=>'+551130000001','method'=>'programmable_voice','until'=>time()+3600]));}
 private function payload():array{return ['route'=>$this->route(),'request_id'=>(string)Str::uuid(),'destination'=>'+5511999990001','consent_confirmed'=>true,'consent_evidence'=>'Own test phone'];}
 private function fake(array $call=['status'=>'dialing','ended'=>false]):void {Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();Http::fake(['https://tenant.example/*'=>function($r)use($call){$d=$r->data();$this->assertSame(hash_hmac('sha256',$r->header('X-Discai-Time')[0].'.discai-internal/calling.'.$r->body(),'secret'),$r->header('X-Discai-Signature')[0]);return Http::response(['tenant'=>'example']+match($d['action']){'catalog'=>['numbers'=>[['method'=>'programmable_voice','number'=>'+551130000001','ready'=>true]]],'reserve'=>['grant'=>['id'=>'fixture-call','method'=>'programmable_voice','access_token'=>'private-grant']],default=>['call'=>$call]});}]);}
 public function test_only_central_administrator_sees_page_and_catalog():void {
  DB::table('portal_memberships')->insert(['tenant_id'=>$this->tenant,'user_id'=>$this->user->id,'role'=>'admin','active'=>true]);$this->get('/platform-calling')->assertForbidden();$this->getJson('/platform-calling/catalog')->assertForbidden();Http::assertNothingSent();
  $this->operator();$this->fake();$this->get('/platform-calling')->assertOk()->assertSee('Fazer uma ligação');$d=$this->getJson('/platform-calling/catalog')->assertOk()->assertDontSee('secret')->json();$this->assertSame('Example',$d['numbers'][0]['company']);$this->assertSame($this->user->id,json_decode(Crypt::decryptString($d['numbers'][0]['route']),true)['user']);$this->assertDatabaseCount('portal_test_calls',0);
 }
 public function test_reservation_is_journaled_no_duplicate_or_cross_tenant_concurrency():void {
  $this->operator();$this->fake();$d=$this->payload();$this->postJson('/platform-calling/calls',$d)->assertOk();$this->postJson('/platform-calling/calls',$d)->assertConflict();$this->postJson('/platform-calling/calls',$this->payload())->assertConflict();Http::assertSentCount(1);
  $c=DB::table('portal_test_calls')->first();$this->assertSame('active',$c->state);$this->assertStringNotContainsString('private-grant',json_encode($c));$this->assertDatabaseCount('portal_test_calls',1);
  $this->getJson('/platform-calling/calls/'.$d['request_id'])->assertOk()->assertJsonPath('call.ended',false);$this->fake(['status'=>'no_answer','ended'=>true]);$this->getJson('/platform-calling/calls/'.$d['request_id'])->assertOk()->assertJsonPath('call.ended',true);$this->postJson('/platform-calling/calls',$this->payload())->assertOk();
 }
 public function test_unknown_provider_response_keeps_lock_until_authoritative_result():void {
  $this->operator();Http::fake(['*'=>Http::response(['error'=>'timeout'],503)]);$d=$this->payload();$this->postJson('/platform-calling/calls',$d)->assertStatus(503);$this->assertDatabaseHas('portal_test_calls',['state'=>'unknown']);$this->postJson('/platform-calling/calls',$this->payload())->assertConflict();
  $this->fake(['found'=>true,'status'=>'pending','ended'=>false]);$this->getJson('/platform-calling/calls/'.$d['request_id'])->assertOk()->assertJsonPath('call.ended',false);
  $this->fake(['status'=>'cancelled','ended'=>true]);$this->postJson('/platform-calling/calls/'.$d['request_id'].'/cancel')->assertOk()->assertJsonPath('call.ended',true);
 }
 public function test_route_cannot_be_tampered_reused_by_another_admin_or_after_expiry():void {
  $this->operator();$d=$this->payload();$this->postJson('/platform-calling/calls',array_replace($d,['route'=>'fake']))->assertUnprocessable();
  $r=json_decode(Crypt::decryptString($d['route']),true);$r['until']=time()-5;$this->postJson('/platform-calling/calls',array_replace($d,['route'=>Crypt::encryptString(json_encode($r))]))->assertUnprocessable();
  $u=User::factory()->create();DB::table('portal_operators')->insert(['user_id'=>$u->id]);$this->actingAs($u)->postJson('/platform-calling/calls',$d)->assertUnprocessable();Http::assertNothingSent();
 }
 public function test_ownership_and_operator_revocation_protect_history_and_actions():void {
  $this->operator();$this->fake();$d=$this->payload();$this->postJson('/platform-calling/calls',$d)->assertOk();$u=User::factory()->create();DB::table('portal_operators')->insert(['user_id'=>$u->id]);$this->actingAs($u)->getJson('/platform-calling/calls/'.$d['request_id'])->assertNotFound();$this->actingAs($this->user);DB::table('portal_operators')->where('user_id',$this->user->id)->delete();$this->getJson('/platform-calling/calls/'.$d['request_id'])->assertForbidden();
 }
 public function test_missing_remote_reservation_is_not_immediately_declared_ended():void {
  $this->operator();Http::fake(['*'=>Http::response([],503)]);$d=$this->payload();$this->postJson('/platform-calling/calls',$d)->assertStatus(503);$this->fake(['found'=>false]);$this->getJson('/platform-calling/calls/'.$d['request_id'])->assertOk()->assertJsonPath('call.ended',false);$this->travel(3)->minutes();$this->getJson('/platform-calling/calls/'.$d['request_id'])->assertOk()->assertJsonPath('call.ended',true);
 }
 public function test_polling_does_not_consume_new_call_allowance():void {
  $this->operator();$this->fake();$d=$this->payload();$this->postJson('/platform-calling/calls',$d)->assertOk();
  for($i=0;$i<12;$i++)$this->getJson('/platform-calling/calls/'.$d['request_id'])->assertOk();
  $this->fake(['status'=>'completed','ended'=>true]);$this->getJson('/platform-calling/calls/'.$d['request_id'])->assertOk();$this->postJson('/platform-calling/calls',$this->payload())->assertOk();
 }

}
