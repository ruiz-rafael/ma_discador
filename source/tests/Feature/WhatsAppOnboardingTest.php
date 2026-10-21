<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WhatsAppOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppOnboardingTest extends TestCase
{
    use RefreshDatabase;
    private User $user;
    private int $sender;
    private string $account;
    private array $requests = [];
    private array $rows = [];
    private string $postMode = 'ok';
    private bool $parent = false;
    private bool $pagination = false;
    private array $capabilities = ['sms' => false, 'voice' => true];
    private bool $foreignNumber = false;
    private string $fetchedStatus = 'PENDING_VERIFICATION';
    private function url(string $suffix = ''): string { return '/api/voice/whatsapp/senders/'.$this->sender.'/onboarding'.$suffix; }
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->account = 'AC'.str_repeat('1', 32);
        config(['whatsapp_test_connection' => ['account_sid' => $this->account, 'api_key' => 'SK'.str_repeat('2', 32), 'api_secret' => 'secret-fixture-never-public', 'auth_token' => 'secret-token-never-public', 'allowed_recipients' => ['+5511999990001'], 'daily_limit' => 10]]);
        $this->user = User::factory()->create(['voice_workspace_id' => 1, 'voice_role' => 'admin']);
        $this->actingAs($this->user);
        $this->sender = DB::table('wa_senders')->insertGetId(['workspace_id' => 1, 'label' => 'Fixture', 'number' => '+5511999990001', 'provider' => 'twilio', 'ownership' => 'external', 'status' => 'unverified']);
        $this->rows = [$this->row('+5511999990002', 'ONLINE', 'b')];
        Http::fake(function ($r) {
            $this->requests[] = ['method' => $r->method(), 'url' => $r->url(), 'data' => $r->data()];
            if ($this->pagination) {
                $this->assertStringStartsWith('https://messaging.twilio.com/', $r->url());
                return Http::response(count($this->requests) === 1 ? ['senders' => [], 'meta' => ['next_page_url' => 'https://untrusted.invalid/?PageToken=next']] : ['senders' => [$this->row('+5511999990001')], 'meta' => []]);
            }
            if ($r->method() === 'POST') {
                if ($this->postMode === 'timeout') throw new ConnectionException('no response');
                if ($this->postMode === 'rejected') return Http::response(['message' => 'private provider details'], 400);
                if ($this->postMode === 'wrong') return Http::response($this->row('+5511999990009'));
                return Http::response($this->row('+5511999990001', isset($r['configuration']['verification_code']) ? 'ONLINE' : 'PENDING_VERIFICATION'), 201);
            }
            if (str_contains($r->url(), '/IncomingPhoneNumbers.json')) return Http::response(['incoming_phone_numbers' => [['phone_number' => '+5511999990001', 'account_sid' => $this->foreignNumber ? 'AC'.str_repeat('f', 32) : $this->account, 'capabilities' => $this->capabilities]]]);
            if (str_contains($r->url(), '/Accounts/')) return Http::response(['sid' => $this->account, 'owner_account_sid' => $this->parent ? $this->account : 'AC'.str_repeat('f', 32)]);
            if (str_contains($r->url(), '/Senders/')) return Http::response($this->row('+5511999990001', $this->fetchedStatus));
            return Http::response(['senders' => $this->rows, 'meta' => ['next_page_url' => null]]);
        });
    }
    private function row(string $number, string $status = 'PENDING_VERIFICATION', string $sid = 'a'): array
    {
        return ['sid' => 'XE'.str_repeat($sid, 32), 'sender_id' => 'whatsapp:'.$number, 'status' => $status, 'configuration' => ['waba_id' => '123456789']];
    }
    private function payload(): array { return ['mode' => 'own', 'display_name' => 'Empresa QA', 'verification_method' => 'sms', 'confirmed' => true]; }
    private function posts(): array { return array_values(array_filter($this->requests, fn ($r) => $r['method'] === 'POST')); }
    private function configure(): void
    {
        $this->putJson('/api/voice/whatsapp/onboarding/settings', ['enabled' => true, 'app_id' => '123456', 'config_id' => '234567', 'solution_id' => '345678', 'graph_version' => 'v24.0'])->assertOk();
    }
    public function test_agent_and_foreign_workspace_cannot_access_or_mutate(): void
    {
        $this->user->forceFill(['voice_role' => 'agent'])->save();
        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url('/register'), $this->payload())->assertForbidden();
        $this->user->forceFill(['voice_role' => 'admin', 'voice_workspace_id' => null])->save();
        $this->getJson($this->url())->assertForbidden();
        Http::assertNothingSent();
    }
    public function test_status_is_local_and_contains_no_credentials(): void
    {
        $r = $this->getJson($this->url())->assertOk()->assertJsonPath('settings.enabled', false);
        $this->assertStringNotContainsString('secret-', $r->getContent());
        $this->assertStringNotContainsString('session_id', $r->getContent());
        Http::assertNothingSent();
    }
    public function test_first_sender_requires_console_and_makes_no_provider_post(): void
    {
        $this->rows = [];
        $this->postJson($this->url('/discover'))->assertOk()->assertJsonPath('can_register', false);
        $this->postJson($this->url('/register'), $this->payload())->assertUnprocessable();
        $this->assertCount(0, $this->posts());
        $this->assertDatabaseCount('wa_onboardings', 0);
    }
    public function test_additional_sender_registers_once_and_never_saves_code(): void
    {
        $this->postJson($this->url('/register'), $this->payload())->assertOk()->assertJsonPath('sender.status', 'PENDING_VERIFICATION');
        $this->postJson($this->url('/register'), $this->payload())->assertConflict();
        $this->assertCount(1, $this->posts());
        $post = $this->posts()[0]['data'];
        $this->assertSame('sms', $post['configuration']['verification_method']);
        $this->assertSame('https://ma.zyrex.ia.br/callbacks/twilio/whatsapp/inbound', $post['webhook']['callback_url']);
        $this->travel(61)->seconds();
        $r = $this->postJson($this->url('/verify'), ['code' => '123456'])->assertOk()->assertJsonPath('sender.status', 'ONLINE');
        $this->assertStringNotContainsString('123456', $r->getContent());
        $this->assertStringNotContainsString('verification_code', json_encode(DB::table('wa_onboardings')->get()));
        $this->assertDatabaseCount('wa_messages', 0);
        $this->assertDatabaseCount('voice_outbound_calls', 0);
    }
    public function test_confirmation_and_code_validation_and_throttle(): void
    {
        $this->postJson($this->url('/register'), $this->payload() + ['extra' => 'ignored'])->assertOk();
        $this->postJson($this->url('/verify'), ['code' => '123'])->assertUnprocessable();
        $this->postJson($this->url('/verify'), ['code' => '123456'])->assertStatus(429);
        $this->assertCount(1, $this->posts());
    }
    public function test_unknown_creation_cannot_be_repeated_and_recovers_from_provider(): void
    {
        $this->postMode = 'timeout';
        $this->postJson($this->url('/register'), $this->payload())->assertOk()->assertJsonPath('onboarding.state', 'unknown');
        $this->postJson($this->url('/register'), $this->payload())->assertConflict();
        $this->assertCount(1, $this->posts());
        $this->rows[] = $this->row('+5511999990001', 'ONLINE');
        $this->postJson($this->url('/discover'))->assertOk()->assertJsonPath('found', true)->assertJsonPath('sender.status', 'ONLINE');
        $this->assertCount(1, $this->posts());
    }
    public function test_rejection_allows_explicit_retry_but_wrong_identity_never_binds(): void
    {
        $this->postMode = 'rejected';
        $this->postJson($this->url('/register'), $this->payload())->assertOk()->assertJsonPath('onboarding.state', 'rejected');
        $this->travel(61)->seconds();
        $this->postMode = 'wrong';
        $this->postJson($this->url('/register'), $this->payload())->assertOk()->assertJsonPath('onboarding.state', 'unknown')->assertJsonPath('sender.provider_sid', null);
    }
    public function test_discover_does_not_change_webhook_and_requires_explicit_action(): void
    {
        $this->rows[] = $this->row('+5511999990001', 'ONLINE');
        $this->postJson($this->url('/discover'))->assertOk()->assertJsonPath('found', true);
        $this->assertCount(0, $this->posts());
        $this->postJson($this->url('/webhook'), ['confirmed' => false])->assertUnprocessable();
        $this->postJson($this->url('/webhook'), ['confirmed' => true])->assertOk();
        $this->assertCount(1, $this->posts());
    }
    public function test_embedded_requires_configuration_and_dedicated_subaccount(): void
    {
        $this->postJson($this->url('/embedded'))->assertUnprocessable();
        Http::assertNothingSent();
        $this->configure(); $this->parent = true;
        $this->postJson($this->url('/embedded'))->assertUnprocessable();
        $this->assertDatabaseCount('wa_onboardings', 0);
    }
    public function test_embedded_flow_requires_bound_session_and_matching_waba(): void
    {
        $this->configure();
        $session = $this->postJson($this->url('/embedded'))->assertOk()->json('session_id');
        $p = ['mode' => 'embedded', 'display_name' => 'Cliente QA', 'confirmed' => true, 'waba_id' => '123456789', 'session_id' => $session];
        $this->postJson($this->url('/register'), array_replace($p, ['waba_id' => '999999']))->assertConflict();
        $this->postJson($this->url('/register'), $p)->assertOk()->assertJsonPath('onboarding.mode', 'embedded');
        $this->assertSame(['waba_id' => '123456789'], $this->posts()[0]['data']['configuration']);
        $this->postJson($this->url('/register'), $p)->assertConflict();
        $this->assertCount(1, $this->posts());
    }
    public function test_expired_or_other_user_session_cannot_register(): void
    {
        $this->configure();
        $session = $this->postJson($this->url('/embedded'))->assertOk()->json('session_id');
        $p = ['mode' => 'embedded', 'display_name' => 'Cliente QA', 'confirmed' => true, 'waba_id' => '123456789', 'session_id' => $session];
        $other = User::factory()->create(['voice_workspace_id' => 1, 'voice_role' => 'admin']);
        $this->actingAs($other)->postJson($this->url('/register'), $p)->assertConflict();
        $this->actingAs($this->user); $this->travel(21)->minutes();
        $this->postJson($this->url('/register'), $p)->assertConflict();
        $this->assertCount(0, $this->posts());
    }
    public function test_supervisor_cannot_change_meta_configuration(): void
    {
        $this->user->forceFill(['voice_role' => 'supervisor'])->save();
        $this->getJson('/api/voice/whatsapp/onboarding/settings')->assertForbidden();
        $this->putJson('/api/voice/whatsapp/onboarding/settings', ['enabled' => true])->assertForbidden();
    }
    public function test_existing_provider_identity_and_account_are_preserved(): void
    {
        DB::table('wa_senders')->where('id', $this->sender)->update(['account_sid' => 'AC'.str_repeat('f', 32)]);
        $this->postJson($this->url('/discover'))->assertConflict();
        Http::assertNothingSent();
    }
    public function test_twilio_voice_only_number_offers_call_and_rejects_sms_before_creation(): void
    {
        DB::table('wa_senders')->where('id', $this->sender)->update(['ownership' => 'twilio']);
        $this->postJson($this->url('/discover'))->assertOk()->assertJsonPath('verification.methods', ['voice'])->assertJsonPath('verification.recommended', 'voice')->assertJsonPath('verification.automatic_sms', false);
        $this->postJson($this->url('/register'), $this->payload())->assertUnprocessable();
        $this->assertCount(0, $this->posts());
        $this->postJson($this->url('/register'), array_replace($this->payload(), ['verification_method' => 'voice']))->assertOk()->assertJsonPath('sender.status', 'PENDING_VERIFICATION');
        $this->assertSame('voice', $this->posts()[0]['data']['configuration']['verification_method']);
        $this->assertCount(1, $this->posts());
    }
    public function test_capabilities_cannot_be_used_from_a_different_twilio_account(): void
    {
        DB::table('wa_senders')->where('id', $this->sender)->update(['ownership' => 'twilio']);$this->foreignNumber = true;
        $this->postJson($this->url('/discover'))->assertUnprocessable();
        $this->postJson($this->url('/register'), $this->payload())->assertUnprocessable();
        $this->assertCount(0, $this->posts());
    }
    public function test_console_pending_sender_can_verify_without_duplicate_registration(): void
    {
        $this->rows[] = $this->row('+5511999990001');
        $this->postJson($this->url('/discover'))->assertOk()->assertJsonPath('found', true);
        $this->assertDatabaseCount('wa_onboardings', 0);
        $r = $this->postJson($this->url('/verify'), ['code' => '654321'])->assertOk()->assertJsonPath('sender.status', 'ONLINE');
        $this->assertCount(1, $this->posts());
        $this->assertStringEndsWith('/Senders/XE'.str_repeat('a', 32), $this->posts()[0]['url']);
        $this->assertStringNotContainsString('654321', $r->getContent());
        $this->assertStringNotContainsString('654321', DB::table('wa_onboardings')->get()->toJson());
        $this->assertDatabaseCount('wa_messages', 0);
        $this->assertDatabaseCount('voice_outbound_calls', 0);
    }
    public function test_provider_state_is_checked_before_submitting_an_otp(): void
    {
        $this->rows[] = $this->row('+5511999990001');$this->postJson($this->url('/discover'))->assertOk();
        $this->fetchedStatus = 'TWILIO_REVIEW';
        $this->postJson($this->url('/verify'), ['code' => '654321'])->assertUnprocessable();
        $this->fetchedStatus = 'ONLINE';
        $this->postJson($this->url('/verify'), ['code' => '654321'])->assertOk()->assertJsonPath('sender.status', 'ONLINE');
        $this->assertCount(0, $this->posts());
    }
    public function test_discovery_paginates_without_following_untrusted_host(): void
    {
        $this->pagination = true;
        $this->postJson($this->url('/discover'))->assertOk()->assertJsonPath('found', true);
        $this->assertCount(2, $this->requests);
        $this->assertStringContainsString('PageToken=next', $this->requests[1]['url']);
    }
}
