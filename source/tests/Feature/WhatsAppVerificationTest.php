<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\WhatsAppVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class WhatsAppVerificationTest extends TestCase
{
    use RefreshDatabase;
    private User $user;
    private string $number = '+551155550001';
    private string $account;
    private array $rows;
    private string $mode = 'ok';
    private string $fetched = 'PENDING_VERIFICATION';
    private array $posts = [];
    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests();
        $this->account = 'AC'.str_repeat('1',32);
        $this->user = User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin']); $this->actingAs($this->user);
        DB::table('voice_sip_trunks')->insert(['id'=>'10000000-0000-4000-8000-000000000001','workspace_id'=>1,'provider'=>'fale_vono','label'=>'Customer line','number'=>$this->number,'configuration'=>'{}','enabled'=>true]);
        config(['whatsapp_test_connection'=>['account_sid'=>$this->account,'api_key'=>'SK'.str_repeat('2',32),'api_secret'=>'private-test-secret','auth_token'=>'private-test-token','allowed_recipients'=>[$this->number],'daily_limit'=>10]]);
        DB::table('wa_onboarding_settings')->insert(['workspace_id'=>1,'settings'=>json_encode(['client_verification'=>['enabled'=>true,'account_sid'=>$this->account,'waba_id'=>'123456789']])]);
        $this->rows = [$this->row('+551155550002','ONLINE','b')];
        Http::fake(function($r){
            if($r->method()==='POST'){
                $this->posts[]=$r->data();
                if($this->mode==='timeout')throw new ConnectionException('private payload');
                if($this->mode==='limit')return Http::response(['code'=>63114,'message'=>'private provider body'],400);
                if($this->mode==='429')return Http::response(['message'=>'private provider body'],429);
                if($this->mode==='error')return Http::response('<html>Failure</html>',503);
                if($this->mode==='wrong')return Http::response($this->row('+551155550009'));
                return Http::response($this->row($this->number,isset($r['configuration']['verification_code'])?'ONLINE':'PENDING_VERIFICATION'),201);
            }
            if(str_contains($r->url(),'/IncomingPhoneNumbers.json'))return Http::response(['incoming_phone_numbers'=>[['phone_number'=>$this->number,'account_sid'=>$this->account,'capabilities'=>['voice'=>true,'sms'=>false]]]]);
            if(str_contains($r->url(),'/Senders/'))return Http::response($this->row($this->number,$this->fetched));
            return Http::response(['senders'=>$this->rows,'meta'=>[]]);
        });
    }
    private function row(string $number,string $status='PENDING_VERIFICATION',string $sid='a'):array{return ['sid'=>'XE'.str_repeat($sid,32),'sender_id'=>'whatsapp:'.$number,'account_sid'=>$this->account,'configuration'=>['waba_id'=>'123456789'],'status'=>$status];}
    private function action(string $action,array $d=[]){return $this->postJson('/api/voice/whatsapp/verification/'.$action,$d+['number'=>$this->number]);}
    private function payload():array{return ['name'=>'Customer Business','confirmed'=>true];}
    private function reception():void{$this->partialMock(WhatsAppVerification::class,fn($mock)=>$mock->shouldReceive('reception')->andReturn(['ready'=>true,'message'=>'Ready fixture']));}
    public function test_catalog_is_local_and_unassigned_or_disabled_numbers_cannot_request():void
    {
        $this->getJson('/api/voice/whatsapp/verification')->assertOk()->assertJsonCount(1,'numbers')->assertJsonPath('numbers.0.operator','Fale Vono');
        $this->action('request',['number'=>'+551155559999']+$this->payload())->assertNotFound();
        DB::table('voice_sip_trunks')->update(['enabled'=>false]);
        $this->action('request',$this->payload())->assertNotFound();Http::assertNothingSent();
    }
    public function test_agent_and_supervisor_cannot_manage_verification():void
    {
        foreach(['agent','supervisor']as$role){$this->user->forceFill(['voice_role'=>$role])->save();$this->getJson('/api/voice/whatsapp/verification')->assertForbidden();$this->action('request',$this->payload())->assertForbidden();}Http::assertNothingSent();
    }
    public function test_tenant_admin_cannot_see_or_change_platform_policy_or_legacy_provider_flow():void
    {
        $this->withoutMiddleware(\App\Http\Middleware\DiscaiTenantSession::class);
        $this->withSession(['discai_identity'=>['platform_admin'=>false]]);
        $this->getJson('/api/voice/whatsapp/verification')->assertOk()->assertJsonPath('platform_admin',false)->assertJsonMissingPath('policy');
        $this->action('settings',['enabled'=>true,'waba_id'=>'123456789'])->assertForbidden();
        $this->getJson('/api/voice/whatsapp/onboarding/settings')->assertForbidden();
        $this->postJson('/api/voice/whatsapp/senders/1/onboarding/register')->assertForbidden();Http::assertNothingSent();
    }
    public function test_unconfigured_tenant_has_clear_local_pending_state():void
    {
        config(['whatsapp_test_connection'=>null]);
        $this->action('status')->assertOk()->assertJsonPath('setup_ready',false)->assertJsonPath('can_request',false)->assertJsonPath('sender_id',null);Http::assertNothingSent();
        $this->assertDatabaseCount('wa_senders',0);
    }
    public function test_voice_request_requires_reception_and_authorization():void
    {
        $this->action('request',['name'=>'Business','confirmed'=>false])->assertUnprocessable();
        $this->action('request',$this->payload())->assertConflict();
        $this->assertCount(0,$this->posts);$this->assertDatabaseCount('wa_number_verifications',0);
    }
    public function test_first_account_or_mismatched_waba_is_never_registered():void
    {
        $this->reception();$this->rows=[];$this->action('request',$this->payload())->assertConflict();
        $this->rows=[$this->row('+551155550002','ONLINE')];$this->rows[0]['configuration']['waba_id']='999999999';
        $this->action('request',$this->payload())->assertConflict();$this->assertCount(0,$this->posts);
    }
    public function test_voice_request_once_code_confirmation_and_no_secret_in_response_or_database():void
    {
        $this->reception();$r=$this->action('request',$this->payload())->assertOk()->assertJsonPath('status','PENDING_VERIFICATION');
        $this->assertStringNotContainsString($this->account,$r->getContent());
        $this->assertSame(['waba_id'=>'123456789','verification_method'=>'voice'],$this->posts[0]['configuration']);
        $this->assertArrayNotHasKey('voice_application_sid',$this->posts[0]['configuration']);
        $this->action('request',$this->payload())->assertStatus(429);$this->assertCount(1,$this->posts);
        $this->action('verify',['code'=>'123456'])->assertStatus(429);
        $this->travel(61)->seconds();
        $r=$this->action('verify',['code'=>'123456'])->assertOk()->assertJsonPath('state','online');
        $this->assertStringNotContainsString('123456"',$r->getContent());
        $this->assertCount(2,$this->posts);
        $this->assertStringNotContainsString('verification_code',DB::table('wa_number_verifications')->get()->toJson());
        $this->assertStringNotContainsString('123456"',DB::table('voice_audit')->get()->toJson());
        $this->assertDatabaseCount('voice_outbound_calls',0);$this->assertDatabaseCount('wa_messages',0);
    }
    public function test_rate_limit_is_persistent_and_never_retried_on_refresh():void
    {
        $this->reception();$this->mode='limit';
        $r=$this->action('request',$this->payload())->assertOk()->assertJsonPath('state','rate_limited')->assertJsonPath('can_request',false);
        $this->assertNotNull($r->json('retry_after'));$this->assertStringNotContainsString('private',$r->getContent());
        $this->travel(2)->hours();$this->action('request',$this->payload())->assertStatus(429);
        $this->action('inspect')->assertOk()->assertJsonPath('state','rate_limited');
        $this->assertCount(1,$this->posts);
    }
    public function test_timeout_and_server_error_never_trigger_automatic_recreation():void
    {
        $this->reception();$this->mode='timeout';$this->action('request',$this->payload())->assertOk()->assertJsonPath('state','unknown');
        $this->travel(61)->seconds();$this->action('request',$this->payload())->assertConflict();
        $this->action('inspect')->assertOk()->assertJsonPath('state','unknown');$this->assertCount(1,$this->posts);
        $this->rows[]=$this->row($this->number);$this->action('inspect')->assertOk()->assertJsonPath('status','PENDING_VERIFICATION');
        $this->mode='error';$this->action('verify',['code'=>'123456'])->assertOk()->assertJsonPath('state','unknown');$this->assertCount(2,$this->posts);
    }
    public function test_existing_sender_is_recovered_without_requesting_another_call():void
    {
        $this->rows[]=$this->row($this->number);
        $this->action('request',$this->payload())->assertOk()->assertJsonPath('status','PENDING_VERIFICATION');$this->assertCount(0,$this->posts);
    }
    public function test_wrong_identity_after_creation_stays_unknown_and_does_not_bind():void
    {
        $this->reception();$this->mode='wrong';$this->action('request',$this->payload())->assertOk()->assertJsonPath('state','unknown')->assertJsonPath('sender_id',null);$this->assertDatabaseCount('wa_senders',0);
    }
    public function test_twilio_voice_line_preserves_sip_and_uses_voice_verification():void
    {
        $this->reception();DB::table('voice_sip_trunks')->update(['provider'=>'twilio']);
        $this->action('request',$this->payload())->assertOk();
        $this->assertSame('voice',$this->posts[0]['configuration']['verification_method']);
        Http::assertNotSent(fn($r)=>$r->method()==='POST'&&str_contains($r->url(),'IncomingPhoneNumbers'));
    }
    public function test_number_removed_after_request_cannot_submit_code():void
    {
        $this->reception();$this->action('request',$this->payload())->assertOk();$this->travel(61)->seconds();
        DB::table('voice_sip_trunks')->update(['enabled'=>false]);$this->action('verify',['code'=>'123456'])->assertNotFound();$this->assertCount(1,$this->posts);
    }
    public function test_reception_checks_queue_membership_presence_and_sip_audio():void
    {
        $q=DB::table('voice_live_queues')->insertGetId(['workspace_id'=>1,'name'=>'Inbound','direction'=>'inbound','inbound_enabled'=>true,'agent_ids'=>json_encode([$this->user->id])]);
        DB::table('voice_inbound_routes')->insert(['workspace_id'=>1,'queue_id'=>$q,'number'=>$this->number,'provider'=>'sip_trunk','enabled'=>true]);
        DB::table('voice_agent_presence')->insert(['user_id'=>$this->user->id,'workspace_id'=>1,'status'=>'available','last_seen_at'=>now()]);
        DB::table('voice_inbound_devices')->insert(['user_id'=>$this->user->id,'workspace_id'=>1,'session_id'=>(string)\Illuminate\Support\Str::uuid(),'identity'=>'fixture','sip_identity'=>'fixture','ready'=>true,'sip_ready'=>false,'last_seen_at'=>now()]);
        $this->action('status')->assertOk()->assertJsonPath('reception.ready',false);
        DB::table('voice_inbound_devices')->update(['sip_ready'=>true]);
        $this->action('status')->assertOk()->assertJsonPath('reception.ready',true);
        $this->travel(46)->seconds();
        $this->action('status')->assertOk()->assertJsonPath('reception.ready',false);Http::assertNothingSent();
    }
    public function test_foreign_sender_account_cannot_create_at_provider():void
    {
        $this->reception();DB::table('wa_senders')->insert(['workspace_id'=>1,'label'=>'Wrong account','number'=>$this->number,'provider'=>'twilio','ownership'=>'external','account_sid'=>'AC'.str_repeat('f',32),'status'=>'unverified']);
        $this->action('request',$this->payload())->assertConflict();$this->assertCount(0,$this->posts);
    }
    public function test_legacy_dedicated_twilio_sip_number_is_in_tenant_inventory_without_exposing_config():void
    {
        $legacy='+551149330254';
        config(['voice_calling_trunk_test'=>['caller_id'=>$legacy,'account_sid'=>$this->account,'sip_password'=>'private-legacy-secret']]);
        $q=DB::table('voice_live_queues')->insertGetId(['workspace_id'=>1,'name'=>'Inbound','agent_ids'=>'[]']);
        DB::table('voice_inbound_routes')->insert(['workspace_id'=>1,'queue_id'=>$q,'number'=>$legacy,'provider'=>'sip_trunk','enabled'=>true]);
        $r=$this->getJson('/api/voice/whatsapp/verification')->assertOk()->assertJsonCount(2,'numbers')->assertJsonPath('numbers.0.number',$legacy)->assertJsonPath('numbers.0.operator','Twilio');
        $this->assertStringNotContainsString('private-legacy',$r->getContent());
        DB::table('voice_inbound_routes')->where('number',$legacy)->delete();
        $this->getJson('/api/voice/whatsapp/verification')->assertOk()->assertJsonCount(1,'numbers');Http::assertNothingSent();
    }
    public function test_configuration_requires_confirmed_account_and_binds_to_current_account():void
    {
        $this->action('settings',['enabled'=>true,'waba_id'=>'999999999'])->assertConflict();
        $this->action('settings',['enabled'=>true,'waba_id'=>'123456789'])->assertOk()->assertJsonPath('policy.account_sid',$this->account);
        $this->assertCount(0,$this->posts);
    }
}
