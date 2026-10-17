<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Crypt,Route};
class PortalAiTest extends TestCase {
 use RefreshDatabase;
 private int $tenant;
 protected function setUp():void {parent::setUp();Http::preventStrayRequests();foreach(glob(database_path('portal-migrations/*.php'))as $m)(require $m)->up();config(['discai.portal'=>true]);Route::middleware('web')->group(base_path('routes/portal.php'));$this->tenant=DB::table('portal_tenants')->insertGetId(['slug'=>'example','name'=>'Empresa exemplo','status'=>'active','url'=>'https://tenant.example','secret'=>Crypt::encryptString('fixture-secret')]);$this->actingAs(User::factory()->create())->withSession(['voice_auth_version'=>0]);}
 private function operator():void {DB::table('portal_operators')->insert(['user_id'=>auth()->id()]);}
 private function fixtureResult():array {return ['local'=>app(\App\Services\AiLocalProvider::class)->settings(1),'openai'=>app(\App\Services\AiProvider::class)->connection(1)];}
 public function test_only_platform_administrator_can_read_or_write_parameters():void {
  DB::table('portal_memberships')->insert(['tenant_id'=>$this->tenant,'user_id'=>auth()->id(),'role'=>'admin','active'=>true]);
  $this->get('/ai-administration')->assertForbidden();$this->get('/ai-administration/'.$this->tenant)->assertForbidden();$this->post('/ai-administration/'.$this->tenant,['action'=>'save'])->assertForbidden();Http::assertNothingSent();
 }
 public function test_platform_reads_settings_from_signed_tenant_origin():void {
  $this->operator();Http::fake(['https://tenant.example/discai-internal/ai'=>Http::response($this->fixtureResult())]);
  $this->get('/ai-administration')->assertOk()->assertSee('Empresa exemplo');$this->get('/ai-administration/'.$this->tenant)->assertOk()->assertSee('Qwen3-8B')->assertSee('OpenAI')->assertSee('Verificar servidor');
  Http::assertSent(function($r){$stamp=$r->header('X-Discai-Time')[0];return hash_equals(hash_hmac('sha256',$stamp.'.discai-internal/ai.'.$r->body(),'fixture-secret'),$r->header('X-Discai-Signature')[0])&&$r['tenant']==='example'&&$r['action']==='read';});
 }
 public function test_save_is_audited_and_does_not_log_or_flash_keys():void {
  $this->operator();Http::fake(['https://tenant.example/discai-internal/ai'=>Http::response($this->fixtureResult())]);
  $this->post('/ai-administration/'.$this->tenant,['action'=>'save','local'=>['text_provider'=>'qwen','api_key'=>'private-fixture-secret'],'openai'=>['enabled'=>false]])->assertRedirect();
  $this->assertDatabaseHas('portal_audit',['event'=>'ai.settings.updated','tenant_id'=>$this->tenant]);$this->assertStringNotContainsString('private-fixture-secret',DB::table('portal_audit')->get()->toJson());$this->assertNull(session('_old_input.local.api_key'));
 }
 public function test_tenant_rejection_is_shown_without_claiming_saved():void {
  $this->operator();Http::fake(['https://tenant.example/discai-internal/ai'=>Http::response(['message'=>'Encerre as conversas em andamento.'],409)]);
  $this->post('/ai-administration/'.$this->tenant,['action'=>'save','local'=>['api_key'=>'secret'],'openai'=>['enabled'=>false]])->assertRedirect()->assertSessionHasErrors('operation');$this->assertDatabaseMissing('portal_audit',['event'=>'ai.settings.updated']);$this->assertNull(session('_old_input.local.api_key'));
 }
}
