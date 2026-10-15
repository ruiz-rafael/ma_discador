<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\Portal\{NumberOrders,NumberProvider};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Crypt,Http,Route};
use Illuminate\Support\Str;
class NumberRequestsTest extends TestCase {
 use RefreshDatabase;
 private User $admin;private User $client;private int $tenant;private string $ac;private string $pn;private string $bu;
 protected function setUp():void {
  parent::setUp();Http::preventStrayRequests();foreach(glob(database_path('portal-migrations/*.php'))as $migration)(require $migration)->up();config(['discai.portal'=>true]);Route::middleware('web')->group(base_path('routes/portal.php'));
  $this->admin=User::factory()->create();$this->client=User::factory()->create();DB::table('portal_operators')->insert(['user_id'=>$this->admin->id]);
  $this->tenant=DB::table('portal_tenants')->insertGetId(['slug'=>'polishop','name'=>'Polishop','url'=>'https://polishop.discai.ia.br','status'=>'active','secret'=>Crypt::encryptString('tenant-secret'),'created_at'=>now(),'updated_at'=>now()]);DB::table('portal_memberships')->insert(['tenant_id'=>$this->tenant,'user_id'=>$this->client->id,'role'=>'admin','active'=>true]);
  $this->ac='AC'.str_repeat('a',32);$this->pn='PN'.str_repeat('b',32);$this->bu='BU'.str_repeat('c',32);
  config(['portal_numbers_test'=>['account_sid'=>$this->ac,'auth_token'=>str_repeat('t',32),'api_key'=>'SK'.str_repeat('d',32),'api_secret'=>str_repeat('s',32)]]);
 }
 private function login(User $u):void {$this->actingAs($u)->withSession(['voice_auth_version'=>0]);}
 private function order():string {$id=(string)Str::uuid();app(NumberOrders::class)->request($this->tenant,$this->client->id,['id'=>$id,'ddd'=>'11','purpose'=>'mixed']);return $id;}
 private function fake(bool $timeout=false):void {
  Http::fake(function($r)use($timeout){
   if(str_contains($r->url(),'AvailablePhoneNumbers'))return Http::response(['available_phone_numbers'=>[['phone_number'=>'+551130001234','capabilities'=>['voice'=>true],'locality'=>'São Paulo'],['phone_number'=>'+552130001234','capabilities'=>['voice'=>true]]]]);
   if(str_contains($r->url(),'pricing.twilio.com'))return Http::response(['price_unit'=>'USD','phone_number_prices'=>[['number_type'=>'local','current_price'=>'5.00']]]);
   if(str_contains($r->url(),'RegulatoryCompliance/Bundles/'))return Http::response(['status'=>'twilio-approved','account_sid'=>$this->ac]);
   if(str_contains($r->url(),'IncomingPhoneNumbers.json')&&$r->method()==='POST'){if($timeout)throw new \Illuminate\Http\Client\ConnectionException('fixture timeout');return Http::response(['sid'=>$this->pn,'phone_number'=>'+551130001234','account_sid'=>$this->ac],201);}
   return Http::response([],404);
  });
 }
 private function buyData(string $id):array {return ['number'=>'+551130001234','quote_token'=>json_decode(app(NumberOrders::class)->order($id)->quote,true)['token'],'bundle_sid'=>$this->bu,'confirm'=>1];}
 public function test_tenant_request_is_isolated_idempotent_and_admin_only():void {
  $this->login($this->client);$id=(string)Str::uuid();$d=['id'=>$id,'ddd'=>'11','purpose'=>'mixed'];
  $this->post('/companies/'.$this->tenant.'/numbers',$d)->assertRedirect();$this->post('/companies/'.$this->tenant.'/numbers',$d)->assertRedirect();$this->assertDatabaseCount('portal_number_requests',1);
  $this->get('/companies/'.$this->tenant.'/numbers')->assertOk()->assertSee('Meus números');$this->get('/number-administration')->assertForbidden();$this->post('/number-administration/'.$id.'/quote')->assertForbidden();
  $this->get('/companies/999/numbers')->assertForbidden();$this->post('/companies/'.$this->tenant.'/numbers',array_replace($d,['id'=>(string)Str::uuid(),'ddd'=>'20']))->assertSessionHasErrors('ddd');
  DB::table('portal_memberships')->update(['role'=>'supervisor']);$this->get('/companies/'.$this->tenant.'/numbers')->assertForbidden();Http::assertNothingSent();
 }
 public function test_quote_filters_ddd_and_purchase_is_explicit_single_and_not_released():void {
  $id=$this->order();$this->fake();$this->login($this->admin);
  $this->post('/number-administration/'.$id.'/quote')->assertRedirect();$q=json_decode(app(NumberOrders::class)->order($id)->quote,true);$this->assertCount(1,$q['numbers']);
  $d=$this->buyData($id);$this->post('/number-administration/'.$id.'/buy',array_diff_key($d,['confirm'=>true]))->assertSessionHasErrors('confirm');
  $this->post('/number-administration/'.$id.'/buy',$d)->assertRedirect();$this->assertDatabaseHas('portal_number_requests',['id'=>$id,'status'=>'purchased','released_at'=>null]);
  $this->post('/number-administration/'.$id.'/buy',$d)->assertSessionHasErrors();
  $this->assertCount(1,Http::recorded(fn($r)=>$r->method()==='POST'&&str_contains($r->url(),'IncomingPhoneNumbers.json')));
  $this->assertDatabaseHas('portal_audit',['event'=>'number.purchase_authorized','user_id'=>$this->admin->id]);
 }
 public function test_timeout_blocks_repeat_purchase_and_reconciles_only_matching_order():void {
  $id=$this->order();$this->fake(true);$this->login($this->admin);$this->post('/number-administration/'.$id.'/quote');$d=$this->buyData($id);$this->post('/number-administration/'.$id.'/buy',$d)->assertRedirect();$this->assertDatabaseHas('portal_number_requests',['id'=>$id,'status'=>'uncertain']);$this->post('/number-administration/'.$id.'/buy',$d)->assertSessionHasErrors();
  Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();Http::fake(['*IncomingPhoneNumbers.json*'=>Http::response(['incoming_phone_numbers'=>[['sid'=>$this->pn,'phone_number'=>$d['number'],'account_sid'=>$this->ac,'capabilities'=>['voice'=>true],'friendly_name'=>'DiscAI pedido '.$id]]])]);
  $this->post('/number-administration/'.$id.'/reconcile')->assertRedirect();$this->assertDatabaseHas('portal_number_requests',['id'=>$id,'status'=>'purchased']);
 }
 public function test_expired_quote_and_changed_price_never_buy():void {
  $id=$this->order();$this->fake();$this->login($this->admin);app(NumberOrders::class)->quote($id,$this->admin->id);$d=$this->buyData($id);DB::table('portal_number_requests')->where('id',$id)->update(['quote_expires_at'=>now()->subMinute()]);$this->post('/number-administration/'.$id.'/buy',$d)->assertSessionHasErrors();
  DB::table('portal_number_requests')->where('id',$id)->update(['quote_expires_at'=>now()->addMinute()]);$q=json_decode(app(NumberOrders::class)->order($id)->quote,true);$q['monthly']='4.00';DB::table('portal_number_requests')->where('id',$id)->update(['quote'=>json_encode($q)]);$this->post('/number-administration/'.$id.'/buy',$d)->assertSessionHasErrors();
  $this->assertCount(0,Http::recorded(fn($r)=>$r->method()==='POST'));
 }
 public function test_client_cannot_see_provider_quote_or_buy_and_cannot_request_when_suspended():void {
  $id=$this->order();$this->fake();app(NumberOrders::class)->quote($id,$this->admin->id);$this->login($this->client);
  $this->get('/companies/'.$this->tenant.'/numbers')->assertOk()->assertDontSee($this->ac)->assertDontSee('USD')->assertDontSee('quote_token');$this->get('/number-administration/'.$id)->assertForbidden();$this->post('/number-administration/'.$id.'/buy',$this->buyData($id))->assertForbidden();
  DB::table('portal_tenants')->where('id',$this->tenant)->update(['status'=>'suspended']);$this->post('/companies/'.$this->tenant.'/numbers',['id'=>(string)Str::uuid(),'ddd'=>'11','purpose'=>'mixed'])->assertNotFound();
 }
 public function test_releasing_checks_both_callbacks_and_delivers_only_to_chosen_tenant():void {
  $id=$this->order();DB::table('portal_number_requests')->where('id',$id)->update(['status'=>'purchased','number'=>'+551130001234','provider_sid'=>$this->pn,'account_sid'=>$this->ac]);
  $published=false;$deliveries=[];
  Http::fake(function($req)use(&$published,&$deliveries){
   $base='https://polishop.discai.ia.br/callbacks/twilio/voice';
   if(str_contains($req->url(),'IncomingPhoneNumbers.json'))return Http::response(['incoming_phone_numbers'=>[['sid'=>$this->pn,'phone_number'=>'+551130001234','account_sid'=>$this->ac,'capabilities'=>['voice'=>true],'voice_url'=>$published?$base.'/inbound':'','status_callback'=>$published?$base.'/inbound/status':'','voice_method'=>'POST','status_callback_method'=>'POST']]]);
   if(str_contains($req->url(),'Applications.json'))return Http::response($req->method()==='GET'?['applications'=>[]]:['sid'=>'AP'.str_repeat('f',32),'account_sid'=>$this->ac,'voice_url'=>$base.'/dial']);
   if(str_contains($req->url(),'/discai-internal/numbers')){$deliveries[]=$req->data();return Http::response(['number'=>'+551130001234']);}
   if(str_contains($req->url(),'/IncomingPhoneNumbers/'.$this->pn)){$published=true;return Http::response(['sid'=>$this->pn]);}
   return Http::response([],404);
  });$this->login($this->admin);$this->post('/number-administration/'.$id.'/release',['confirm'=>1])->assertRedirect()->assertSessionHasNoErrors();$this->assertDatabaseHas('portal_number_requests',['id'=>$id,'status'=>'available']);$this->assertCount(2,$deliveries);$this->assertSame('polishop',$deliveries[0]['tenant']);$this->assertTrue($deliveries[1]['activate']);
 }
 public function test_existing_number_with_route_cannot_be_adopted():void {
  $id=$this->order();Http::fake(['*'=>Http::response(['incoming_phone_numbers'=>[['sid'=>$this->pn,'phone_number'=>'+551130001234','account_sid'=>$this->ac,'capabilities'=>['voice'=>true],'voice_url'=>'https://existing.example.test/voice']]])]);$this->login($this->admin);
  $this->post('/number-administration/'.$id.'/adopt',['confirm'=>1,'number'=>'+551130001234'])->assertSessionHasErrors();$this->assertDatabaseHas('portal_number_requests',['id'=>$id,'status'=>'requested']);$this->assertCount(0,Http::recorded(fn($r)=>$r->method()==='POST'));
 }
 public function test_failed_callback_confirmation_never_activates_or_marks_available():void {
  $id=$this->order();DB::table('portal_number_requests')->where('id',$id)->update(['status'=>'purchased','number'=>'+551130001234','provider_sid'=>$this->pn,'account_sid'=>$this->ac]);
  DB::table('portal_number_connections')->insert(['tenant_id'=>$this->tenant,'application_sid'=>'AP'.str_repeat('f',32)]);
  Http::fake(function($req){
   if(str_contains($req->url(),'IncomingPhoneNumbers.json'))return Http::response(['incoming_phone_numbers'=>[['sid'=>$this->pn,'phone_number'=>'+551130001234','account_sid'=>$this->ac,'capabilities'=>['voice'=>true],'voice_url'=>'','status_callback'=>'']]]);
   if(str_contains($req->url(),'/discai-internal/numbers'))return Http::response(['number'=>'+551130001234']);
   if(str_contains($req->url(),'/IncomingPhoneNumbers/'.$this->pn))return Http::response(['sid'=>$this->pn]);
   return Http::response([],404);
  });$this->login($this->admin);$this->post('/number-administration/'.$id.'/release',['confirm'=>1])->assertSessionHasErrors();
  $this->assertDatabaseHas('portal_number_requests',['id'=>$id,'status'=>'purchased','released_at'=>null]);
  $this->assertCount(0,Http::recorded(fn($req)=>str_contains($req->url(),'/discai-internal/numbers')&&!empty($req['activate'])));
  $this->assertCount(0,Http::recorded(fn($req)=>$req->method()==='POST'&&str_contains($req->url(),'IncomingPhoneNumbers.json')));
 }
 public function test_regulatory_rejection_keeps_request_open_without_release():void {
  $id=$this->order();$this->fake();app(NumberOrders::class)->quote($id,$this->admin->id);$d=$this->buyData($id);$this->login($this->admin);
  Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();
  Http::fake(function($req){
   if(str_contains($req->url(),'AvailablePhoneNumbers'))return Http::response(['available_phone_numbers'=>[['phone_number'=>'+551130001234','capabilities'=>['voice'=>true]]]]);
   if(str_contains($req->url(),'pricing.twilio.com'))return Http::response(['price_unit'=>'USD','phone_number_prices'=>[['number_type'=>'local','current_price'=>'5.00']]]);
   if(str_contains($req->url(),'RegulatoryCompliance/Bundles/'))return Http::response(['status'=>'pending-review','account_sid'=>$this->ac]);
   return Http::response([],404);
  });$this->post('/number-administration/'.$id.'/buy',$d)->assertSessionHasErrors();$this->assertDatabaseHas('portal_number_requests',['id'=>$id,'status'=>'requested','number'=>null]);$this->assertCount(0,Http::recorded(fn($req)=>$req->method()==='POST'));
 }

}
