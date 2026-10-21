<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppPublicProfileTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    private int $id;
    private string $sid;
    private array $photos = [];
    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests();
        $this->admin = User::factory()->create(['voice_workspace_id' => 1, 'voice_role' => 'admin']);
        $this->actingAs($this->admin); $this->sid = 'XE'.str_repeat('a',32);
        $this->id = DB::table('wa_senders')->insertGetId(['workspace_id'=>1,'label'=>'Internal label','number'=>'+5511999990001','provider'=>'twilio','ownership'=>'external','status'=>'unverified']);
    }
    protected function tearDown(): void { foreach($this->photos as $path) @unlink($path); parent::tearDown(); }
    private function url(string $action=''):string { return '/api/voice/whatsapp/senders/'.$this->id.'/profile'.$action; }
    private function connect(): void
    {
        config(['whatsapp_test_connection'=>['account_sid'=>'AC'.str_repeat('1',32),'api_key'=>'SK'.str_repeat('2',32),'api_secret'=>'private-fixture-secret','auth_token'=>'private-fixture-token','allowed_recipients'=>['+5511999990001'],'daily_limit'=>10]]);
        DB::table('wa_senders')->where('id',$this->id)->update(['provider_sid'=>$this->sid,'status'=>'ONLINE']);
    }
    private function row():array { return ['sid'=>$this->sid,'sender_id'=>'whatsapp:+5511999990001','status'=>'ONLINE','profile'=>['name'=>'Current Name','logo_url'=>'https://example.test/logo.jpg','pending_display_name'=>'New Name','pending_display_name_status'=>'PENDING_REVIEW']]; }
    private function draft():void { $this->putJson($this->url(),['name'=>'New Name','about'=>'Atendimento'])->assertOk(); }
    public function test_draft_works_without_credentials_and_does_not_change_internal_label():void
    {
        $this->draft(); $this->getJson($this->url())->assertOk()->assertJsonPath('draft.name','New Name')->assertJsonPath('can_publish',false);
        $this->assertDatabaseHas('wa_senders',['id'=>$this->id,'label'=>'Internal label','status'=>'unverified']);
        $this->postJson($this->url('/publish'),['confirmed'=>true])->assertUnprocessable(); Http::assertNothingSent();
    }
    public function test_only_local_admin_can_access_profile():void
    {
        foreach(['agent','supervisor'] as $role){ $this->admin->forceFill(['voice_role'=>$role])->save();$this->getJson($this->url())->assertForbidden();$this->putJson($this->url(),['name'=>'Changed','about'=>''])->assertForbidden(); }
        $this->admin->forceFill(['voice_role'=>'admin','voice_workspace_id'=>null])->save();$this->getJson($this->url())->assertForbidden();Http::assertNothingSent();
    }
    public function test_photo_is_public_image_but_never_published_automatically():void
    {
        $file=UploadedFile::fake()->createWithContent('logo.png',file_get_contents(base_path('tests/Fixtures/whatsapp-profile.png')));
        $r=$this->post($this->url('/photo'),['photo'=>$file],['Accept'=>'application/json'])->assertOk();
        $url=$r->json('draft.logo_url');$path=parse_url($url,PHP_URL_PATH);$this->photos[]=storage_path('app/private/wa-profile-images/'.basename($path));
        $this->assertStringStartsWith('https://ma.zyrex.ia.br/media/whatsapp-profile/',$url);
        auth()->logout();$this->get($path)->assertOk()->assertHeader('Content-Type','image/png')->assertHeader('X-Content-Type-Options','nosniff');Http::assertNothingSent();
    }
    public function test_svg_transparency_and_external_photo_urls_are_not_accepted():void
    {
        $this->post($this->url('/photo'),['photo'=>UploadedFile::fake()->createWithContent('x.svg','<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post($this->url('/photo'),['photo'=>UploadedFile::fake()->createWithContent('x.png',file_get_contents(base_path('tests/Fixtures/whatsapp-profile-transparent.png')))],['Accept'=>'application/json'])->assertUnprocessable();
        $this->putJson($this->url(),['name'=>'Name','about'=>'','logo_url'=>'http://127.0.0.1/private'])->assertOk()->assertJsonPath('draft.logo_url','');Http::assertNothingSent();
    }
    public function test_accepted_update_does_not_overwrite_confirmed_name_or_webhooks():void
    {
        $this->connect();$this->draft();Http::fake(fn($r)=>Http::response($r->method()==='POST'?['sid'=>$this->sid,'profile'=>['display_name_status'=>'updating']]:$this->row(),$r->method()==='POST'?202:200));
        $r=$this->postJson($this->url('/publish'),['confirmed'=>true])->assertOk()->assertJsonPath('submission_state','submitted')->assertJsonPath('confirmed.name','Current Name');
        $this->assertStringNotContainsString('private-fixture',$r->getContent());
        Http::assertSent(fn($r)=>$r->method()==='POST'&&$r->data()===['profile'=>['name'=>'New Name','about'=>'Atendimento']]);
        $this->postJson($this->url('/sync'))->assertOk()->assertJsonPath('confirmed.pending_display_name_status','PENDING_REVIEW')->assertJsonPath('draft.name','New Name');
    }
    public function test_identity_mismatch_prevents_publication():void
    {
        $this->connect();$this->draft();Http::fake(fn()=>Http::response(array_replace($this->row(),['sender_id'=>'whatsapp:+5511888888888'])));
        $this->postJson($this->url('/publish'),['confirmed'=>true])->assertConflict();Http::assertNotSent(fn($r)=>$r->method()==='POST');
    }
    public function test_timeout_preserves_snapshot_and_requires_reconciliation():void
    {
        $this->connect();$this->draft();Http::fake(function($r){if($r->method()==='POST')throw new ConnectionException('private timeout');return Http::response($this->row());});
        $this->postJson($this->url('/publish'),['confirmed'=>true])->assertOk()->assertJsonPath('submission_state','unknown')->assertJsonPath('confirmed.name','Current Name');
        $this->postJson($this->url('/publish'),['confirmed'=>true])->assertConflict();
        $this->postJson($this->url('/sync'))->assertOk()->assertJsonPath('submission_state','consulted');
    }
    public function test_provider_rejection_or_partial_failure_never_claims_publication():void
    {
        $this->connect();$this->draft();Http::fake(fn($r)=>Http::response($r->method()==='POST'?['message'=>'private provider error']:$this->row(),$r->method()==='POST'?400:200));
        $this->postJson($this->url('/publish'),['confirmed'=>true])->assertOk()->assertJsonPath('submission_state','rejected')->assertJsonPath('confirmed.name','Current Name');
    }
    public function test_partial_failure_keeps_the_confirmed_profile():void
    {
        $this->connect();$this->draft();Http::fake(fn($r)=>Http::response($r->method()==='POST'?['sid'=>$this->sid,'profile'=>['display_name_status'=>'error']]:$this->row()));
        $this->postJson($this->url('/publish'),['confirmed'=>true])->assertOk()->assertJsonPath('submission_state','partial_error');
    }
}
