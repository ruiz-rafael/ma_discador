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
 public function test_provisioning_admin_can_view_and_request_without_provisioned_domain():void {
  DB::table('portal_tenants')->where('id',$this->tenant)->update(['status'=>'provisioning','url'=>null,'secret'=>null]);
  $this->login($this->client);$url='/companies/'.$this->tenant.'/numbers';
  $this->get($url)->assertOk()->assertSee('Solicitar novo número')->assertSee('Sua empresa está em preparação')->assertSee('Voltar à minha empresa')->assertDontSee('Voltar à operação');
  $id=(string)Str::uuid();$d=['id'=>$id,'ddd'=>'11','purpose'=>'mixed'];
  $this->post($url,$d)->assertRedirect();$this->post($url,$d)->assertRedirect();
  $this->assertDatabaseCount('portal_number_requests',1);$this->assertDatabaseHas('portal_number_requests',['id'=>$id,'tenant_id'=>$this->tenant,'requested_by'=>$this->client->id,'status'=>'requested']);
  $this->get($url)->assertOk()->assertSee('Solicitado');
  $this->login($this->admin);$this->get('/number-administration')->assertOk()->assertSee('Polishop');
  $this->post('/number-administration/'.$id.'/quote')->assertRedirect()->assertSessionHasErrors('operation');
  $this->post('/number-administration/'.$id.'/release',['confirm'=>1])->assertRedirect()->assertSessionHasErrors('operation');
  $this->assertDatabaseHas('portal_number_requests',['id'=>$id,'status'=>'requested','released_at'=>null]);
  Http::assertNothingSent();
 }
 public function test_provisioning_numbers_remain_restricted_to_authorized_admins():void {
  DB::table('portal_tenants')->where('id',$this->tenant)->update(['status'=>'provisioning']);
  $url='/companies/'.$this->tenant.'/numbers';$d=['id'=>(string)Str::uuid(),'ddd'=>'11','purpose'=>'mixed'];
  $outsider=User::factory()->create();$this->login($outsider);$this->get($url)->assertForbidden();$this->post($url,$d)->assertForbidden();
  $this->login($this->client);
  foreach(['supervisor','agent']as $role){DB::table('portal_memberships')->where('tenant_id',$this->tenant)->update(['role'=>$role]);$this->get($url)->assertForbidden();$this->post($url,$d)->assertForbidden();}
  DB::table('portal_memberships')->where('tenant_id',$this->tenant)->update(['role'=>'admin','active'=>false]);$this->get($url)->assertForbidden();$this->post($url,$d)->assertForbidden();
  $this->assertDatabaseCount('portal_number_requests',0);Http::assertNothingSent();
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

 private function assignmentData(string $number='+551130001234'):array {return ['id'=>(string)Str::uuid(),'tenant_id'=>$this->tenant,'number'=>$number,'purpose'=>'mixed','notes'=>'Incluído na contratação inicial.','confirm'=>1];}
 private function fakeOwned(array $replace=[]):void {Http::fake(['*IncomingPhoneNumbers.json*'=>Http::response(['incoming_phone_numbers'=>[array_replace(['sid'=>$this->pn,'phone_number'=>'+551130001234','account_sid'=>$this->ac,'capabilities'=>['voice'=>true]],$replace)]])]);}
 public function test_direct_assignment_is_operator_only_and_requires_confirmation():void {
  $this->login($this->client);$this->get('/number-administration/assign')->assertForbidden();$this->post('/number-administration/assign',$this->assignmentData())->assertForbidden();
  $this->login($this->admin);$this->get('/number-administration/assign?tenant='.$this->tenant)->assertOk()->assertSee('Vincular número existente')->assertSee('Polishop');
  $this->post('/number-administration/assign',array_diff_key($this->assignmentData(),['confirm'=>true]))->assertSessionHasErrors('confirm');
  $this->post('/number-administration/assign',array_replace($this->assignmentData(),['number'=>'1130001234']))->assertSessionHasErrors('number');
  $this->assertDatabaseCount('portal_number_requests',0);Http::assertNothingSent();
 }
 public function test_direct_assignment_has_no_client_request_no_purchase_and_is_idempotent():void {
  $this->fakeOwned();$this->login($this->admin);$d=$this->assignmentData();
  foreach([1,2]as $_)$this->post('/number-administration/assign',$d)->assertRedirect('/number-administration/'.$d['id'])->assertSessionHasNoErrors();
  $this->assertDatabaseCount('portal_number_requests',1);$this->assertDatabaseHas('portal_number_requests',['source'=>'admin_assignment','tenant_id'=>$this->tenant,'number'=>$d['number'],'status'=>'purchased','requested_by'=>$this->admin->id,'purchased_at'=>null,'released_at'=>null]);
  $this->assertDatabaseHas('portal_audit',['event'=>'number.assigned_by_admin','user_id'=>$this->admin->id]);
  $this->assertCount(1,Http::recorded());$this->assertCount(0,Http::recorded(fn($r)=>$r->method()!=='GET'));
  $this->get('/number-administration/'.$d['id'])->assertOk()->assertSee('Liberar para Polishop')->assertSee('Número conferido');
  $this->login($this->client);$this->get('/companies/'.$this->tenant.'/numbers')->assertOk()->assertSee('Atribuído pela administração')->assertSee('Incluído na contratação inicial.')->assertDontSee($this->ac);
 }
 public function test_direct_assignment_supports_international_numbers_without_fake_brazilian_ddd():void {
  $d=$this->assignmentData('+12025550123');$this->fakeOwned(['phone_number'=>$d['number']]);$this->login($this->admin);
  $this->post('/number-administration/assign',$d)->assertSessionHasNoErrors();$this->assertDatabaseHas('portal_number_requests',['id'=>$d['id'],'ddd'=>null,'number'=>$d['number']]);
  $this->get('/number-administration')->assertOk()->assertSee('Número internacional');$this->get('/number-administration/'.$d['id'])->assertOk()->assertDontSee('DDD ');
 }
 public function test_direct_assignment_cannot_take_an_existing_allocation_or_reuse_a_form_for_another_tenant():void {
  $this->fakeOwned();$this->login($this->admin);$d=$this->assignmentData();$this->post('/number-administration/assign',$d)->assertSessionHasNoErrors();
  $other=DB::table('portal_tenants')->insertGetId(['slug'=>'other','name'=>'Outra empresa','status'=>'active']);
  $this->post('/number-administration/assign',array_replace($d,['tenant_id'=>$other]))->assertSessionHasErrors();
  $this->post('/number-administration/assign',array_replace($d,['id'=>(string)Str::uuid(),'tenant_id'=>$other]))->assertSessionHasErrors();
  $this->assertDatabaseCount('portal_number_requests',1);$this->assertDatabaseHas('portal_number_requests',['id'=>$d['id'],'tenant_id'=>$this->tenant]);
 }
 public function test_direct_assignment_rejects_routes_wrong_account_and_suspended_tenant():void {
  $this->login($this->admin);
  foreach([['voice_url'=>'https://existing.example.test/voice'],['status_callback'=>'https://existing.example.test/status'],['trunk_sid'=>'TK'.str_repeat('a',32)],['voice_application_sid'=>'AP'.str_repeat('a',32)],['account_sid'=>'AC'.str_repeat('f',32)],['capabilities'=>['voice'=>false]]]as $replace){
   Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();$this->fakeOwned($replace);$this->post('/number-administration/assign',$this->assignmentData())->assertSessionHasErrors();$this->assertDatabaseCount('portal_number_requests',0);
  }
  DB::table('portal_tenants')->where('id',$this->tenant)->update(['status'=>'suspended']);Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();
  $this->post('/number-administration/assign',$this->assignmentData())->assertSessionHasErrors();Http::assertNothingSent();
 }
 public function test_direct_assignment_runs_real_http_and_recovers_from_provider_error():void {
  // The installed Postman executable is the desktop app, not its simulation CLI.
  // This isolated loopback server exercises the real HTTP client without provider access.
  $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
  $log=tempnam(sys_get_temp_dir(),'number-http-');$err=tempnam(sys_get_temp_dir(),'number-http-err-');
  $proc=proc_open([PHP_BINARY,'-S',$address,base_path('tests/Fixtures/number-provider.php')],[0=>['pipe','r'],1=>['file',$err,'a'],2=>['file',$err,'a']],$pipes,null,['NUMBER_SIM_LOG'=>$log]);
  try{
   for($i=0;$i<50;$i++){if($ready=@stream_socket_client('tcp://'.$address,$errno,$error,.1)){fclose($ready);break;}usleep(20000);}
   $this->assertTrue(isset($ready)&&$ready!==false,'Simulation did not start');
   Http::swap(new \Illuminate\Http\Client\Factory);
   Http::globalRequestMiddleware(function($request)use($address){if($request->getUri()->getHost()!=='api.twilio.com')throw new \RuntimeException('Unexpected simulation host');return $request->withUri(new \GuzzleHttp\Psr7\Uri('http://'.$address.$request->getUri()->getPath().'?'.$request->getUri()->getQuery()));});
   $this->login($this->admin);
   $this->post('/number-administration/assign',$this->assignmentData('+12025550003'))->assertSessionHasErrors();$this->assertDatabaseCount('portal_number_requests',0);
   $d=$this->assignmentData('+12025550123');$this->post('/number-administration/assign',$d)->assertRedirect('/number-administration/'.$d['id'])->assertSessionHasNoErrors();
   $events=array_map(fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));$this->assertCount(2,$events);$this->assertSame(['GET','GET'],array_column($events,'method'));$this->assertSame([500,200],array_column($events,'status'));
   $this->assertDatabaseHas('portal_number_requests',['id'=>$d['id'],'status'=>'purchased','released_at'=>null]);
  }finally{proc_terminate($proc);proc_close($proc);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);unlink($log);unlink($err);}
 }

}
