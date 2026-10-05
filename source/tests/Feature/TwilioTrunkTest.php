<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwilioTrunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, File, Http};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TwilioTrunkTest extends TestCase
{
    use RefreshDatabase;
    private string $originalStorage;
    private string $temporaryStorage;
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        $this->temporaryStorage = sys_get_temp_dir().'/ma-twilio-test-'.bin2hex(random_bytes(8));
        app()->useStoragePath($this->temporaryStorage);
        Http::preventStrayRequests();
    }
    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryStorage);
        app()->useStoragePath($this->originalStorage);
        parent::tearDown();
    }
    private function fixture(): array
    {
        return ['account_sid'=>'AC'.str_repeat('1',32), 'trunk_sid'=>'TK'.str_repeat('2',32), 'termination_host'=>'zyrex-test.pstn.twilio.com', 'sip_username'=>'ma-test', 'sip_password'=>'TestOnly-Sip!12345', 'caller_id'=>'+551130000001', 'edge'=>'frankfurt', 'planned_concurrency'=>20, 'planned_cps'=>1];
    }
    private function agent(int $workspace = 1): User
    {
        $u=User::factory()->create(); $u->forceFill(['voice_workspace_id'=>$workspace,'voice_role'=>'supervisor'])->save(); return $u;
    }
    public function test_configuration_status_is_authenticated_and_limited_to_original_workspace(): void
    {
        $this->getJson('/api/voice/provider/twilio')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/voice/provider/twilio')->assertForbidden();
        $other=DB::table('voice_workspaces')->insertGetId(['name'=>'Other']);
        $this->actingAs($this->agent($other))->getJson('/api/voice/provider/twilio')->assertForbidden();
        $this->actingAs($this->agent())->getJson('/api/voice/provider/twilio')->assertOk()->assertJsonPath('state','awaiting_configuration')->assertJsonPath('external_calls_enabled',false)->assertHeader('Cache-Control','no-store, private');
        $this->postJson('/api/voice/provider/twilio',$this->fixture())->assertStatus(405);
    }
    public function test_credentials_are_encrypted_and_never_returned_to_browser(): void
    {
        $service=app(TwilioTrunk::class);$data=$this->fixture();$service->save($data);
        $encrypted=file_get_contents(storage_path('app/private/voice/twilio/connection.enc'));
        $this->assertStringNotContainsString($data['sip_password'],$encrypted);
        $this->assertStringNotContainsString($data['account_sid'],$encrypted);
        $this->assertSame($data,$service->read());
        $response=$this->actingAs($this->agent())->getJson('/api/voice/provider/twilio')->assertOk()->assertJsonPath('state','configured_unverified')->assertJsonPath('planned_concurrency',20)->assertJsonPath('external_calls_enabled',false)->assertJsonPath('capacity_verified',false)->assertJsonPath('number_verified',false);
        foreach (['sip_password','sip_username','account_sid','trunk_sid','caller_id','termination_host'] as $field) {
            $response->assertJsonMissingPath($field); $this->assertStringNotContainsString($data[$field],$response->getContent());
        }
        $this->assertDatabaseCount('voice_attempts',0);
    }
    public function test_staging_has_tls_srtp_but_no_registration_or_call_route(): void
    {
        $service=app(TwilioTrunk::class);$service->save($this->fixture());$service->stage();
        $path=storage_path('app/private/voice/twilio/pjsip-staged.conf');$text=file_get_contents($path);
        $this->assertStringContainsString('zyrex-test.pstn.frankfurt.twilio.com:5061\;transport=tls',$text);
        $this->assertStringContainsString('verify_server=yes',$text);
        $this->assertStringContainsString('media_encryption=sdes',$text);
        $this->assertStringNotContainsString('type=registration',$text);
        $this->assertStringNotContainsString('Dial(',$text);
        $this->assertSame(0600,fileperms($path)&0777);
        $this->assertSame(0600,fileperms(storage_path('app/private/voice/twilio/connection.enc'))&0777);
    }
    public function test_invalid_configuration_cannot_inject_pjsip_or_expand_to_other_hosts(): void
    {
        foreach ([['sip_password',"SafePassword123\n[evil]"],['sip_password','SafePassword;comment'],['sip_username',"ma\nauth=no"],['termination_host','evil.example'],['termination_host','ma.pstn.twilio.com.evil.example'],['caller_id','+551130000001;Dial(evil)'],['planned_concurrency',0],['planned_cps',101]] as [$key,$value]) {
            $data=$this->fixture();$data[$key]=$value;
            try { app(TwilioTrunk::class)->save($data);$this->fail('Invalid value accepted for '.$key); }
            catch (ValidationException $e) { $this->assertArrayHasKey($key,$e->errors()); }
        }
        $this->assertFileDoesNotExist(storage_path('app/private/voice/twilio/connection.enc'));
    }
    public function test_corrupt_private_configuration_is_reported_without_enabling_calls(): void
    {
        $service=app(TwilioTrunk::class);$service->save($this->fixture());
        file_put_contents(storage_path('app/private/voice/twilio/connection.enc'),'corrupt-secret');
        $response=$this->actingAs($this->agent())->getJson('/api/voice/provider/twilio')->assertOk()->assertJsonPath('state','configuration_unavailable')->assertJsonPath('external_calls_enabled',false);
        $this->assertStringNotContainsString('corrupt-secret',$response->getContent());
    }
    public function test_interactive_command_prepares_private_configuration_without_network_requests(): void
    {
        $d=$this->fixture();
        $this->artisan('voice:twilio:configure')
            ->expectsQuestion('Account SID (AC...)',$d['account_sid'])
            ->expectsQuestion('Trunk SID (TK...)',$d['trunk_sid'])
            ->expectsQuestion('Termination SIP URI, sem sip: (nome.pstn.twilio.com)',$d['termination_host'])
            ->expectsQuestion('Usuário da Credential List SIP',$d['sip_username'])
            ->expectsQuestion('Senha SIP da Credential List (não é o Auth Token da conta)',$d['sip_password'])
            ->expectsQuestion('Número de origem Twilio em E.164 (+...)',$d['caller_id'])
            ->expectsChoice('Edge inicial; escolher conforme latência da VM','frankfurt',['frankfurt','dublin','sao-paulo','ashburn','umatilla','singapore','tokyo','sydney'])
            ->expectsQuestion('Chamadas simultâneas planejadas; não altera limite ativo','20')
            ->expectsQuestion('Novas chamadas por segundo planejadas; confirmar na Twilio','1')
            ->assertSuccessful();
        $this->assertFileExists(storage_path('app/private/voice/twilio/pjsip-staged.conf'));
        $this->assertFalse(app(TwilioTrunk::class)->status()['external_calls_enabled']);
    }
}
