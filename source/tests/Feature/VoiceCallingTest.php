<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VoiceCalling;
use App\Services\VoiceCallingBundle;
use App\Services\VoiceCallingConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VoiceCallingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['http://10.241.50.10:8088/httpstatus' => Http::response('OK')]);
        config(['voice_calling_test' => ['allowed_recipients' => ['+5511999990001'], 'daily_limit' => 20, 'max_seconds' => 30, 'ring_seconds' => 10, 'caller_id_confirmed' => true, 'sip_username' => 'ma-calling', 'sip_password' => str_repeat('a', 64), 'event_secret' => str_repeat('b', 64)], 'voice_calling_trunk_test' => ['account_sid' => 'AC'.str_repeat('1', 32), 'trunk_sid' => 'TK'.str_repeat('2', 32), 'termination_host' => 'fixture.pstn.twilio.com', 'sip_username' => 'ma-fixture', 'sip_password' => 'OnlyTestPassword123', 'caller_id' => '+551130000001', 'edge' => 'frankfurt', 'planned_concurrency' => 2, 'planned_cps' => 1], 'voice_calling_installed_test' => true, 'voice_audio_test' => ['enabled' => true, 'sip_username' => 'ma-audio', 'sip_password' => 'echo-only', 'event_secret' => 'echo-secret']]);
        $this->user = User::factory()->create();
        $this->user->forceFill(['voice_workspace_id' => 1, 'voice_role'=>'supervisor'])->save();
        $this->actingAs($this->user);
        $this->contact = DB::table('voice_contacts')->insertGetId(['workspace_id' => 1, 'name' => 'QA', 'phone' => '+5511999990001', 'original_phone' => '+5511999990001', 'source' => 'QA fixture', 'consent' => true, 'consent_evidence' => 'Test permission']);
    }

    private function payload(): array
    {
        return ['contact_id' => $this->contact, 'idempotency_key' => (string) Str::uuid(), 'consent_confirmed' => true, 'consent_evidence' => 'Autorização expressa do contato de teste'];
    }

    private function reserve(?array $d = null): array
    {
        return $this->postJson('/api/voice/calling/calls', $d ?? $this->payload())->assertOk()->json();
    }

    private function event(array $grant, string $type = 'start', array $extra = [], ?string $signature = null)
    {
        preg_match('~sip:([a-f0-9]{64})@~', $grant['destination'], $m);
        $d = ['token' => $m[1], 'event' => $type, 'channel_id' => 'test.123'] + $extra;
        $body = json_encode($d);
        $stamp = (string) time();

        return $this->call('POST', '/internal/voice/calling/event', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_VOICE_TIMESTAMP' => $stamp, 'HTTP_X_VOICE_SIGNATURE' => $signature ?? hash_hmac('sha256', $stamp.'.'.$body, config('voice_calling_test.event_secret'))], $body);
    }

    public function test_ui_is_scoped_and_preparation_does_not_claim_trunk_connectivity(): void
    {
        config(['voice_calling_installed_test' => false]);
        $r = $this->getJson('/api/voice/calling')->assertOk()->assertJsonPath('connection.ready', false)->assertJsonPath('connection.incoming_enabled', false);
        $this->assertStringNotContainsString(config('voice_calling_test.sip_password'), $r->getContent());
        $this->postJson('/api/voice/calling/calls', $this->payload())->assertStatus(503);
        $this->user->forceFill(['voice_workspace_id' => null])->save();
        $this->getJson('/api/voice/calling')->assertForbidden();
        $this->assertDatabaseCount('voice_outbound_calls', 0);
    }

    public function test_reservation_is_idempotent_and_does_not_dial(): void
    {
        $d = $this->payload();
        $g = $this->reserve($d);
        $this->postJson('/api/voice/calling/calls', $d)->assertOk()->assertJsonPath('id', $g['id']);
        $this->assertStringStartsWith('sip:', $g['destination']);
        $this->assertStringNotContainsString('+5511999990001', $g['destination']);
        $this->assertSame('ma-calling', $g['username']);
        $this->assertDatabaseCount('voice_outbound_calls', 1);
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'status' => 'pending', 'started_at' => null]);
        $d['consent_evidence'] = 'Outro registro válido';
        $this->postJson('/api/voice/calling/calls', $d)->assertConflict();
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_single_use_grant_rechecks_consent_before_dialing(): void
    {
        $g = $this->reserve();
        DB::table('voice_contacts')->where('id', $this->contact)->update(['suppressed_at' => now()]);
        $this->event($g)->assertOk()->assertJsonPath('allowed', false);
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'status' => 'pending']);
    }

    public function test_signed_lifecycle_and_replay_cannot_create_another_call(): void
    {
        $g = $this->reserve();
        $this->event($g, signature: 'wrong')->assertUnauthorized();
        $this->event($g)->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('number', '+5511999990001');
        $this->event($g)->assertOk()->assertJsonPath('allowed', true);
        $this->event($g, 'answered')->assertOk();
        $this->event($g, 'finish', ['dial_status' => 'ANSWER', 'cause' => '16', 'bill_seconds' => 21])->assertOk();
        $this->event($g, 'finish', ['dial_status' => 'ANSWER', 'cause' => '16', 'bill_seconds' => 21])->assertOk();
        $this->event($g)->assertOk()->assertJsonPath('allowed', false);
        $this->event($g, 'answered')->assertOk()->assertJsonPath('allowed', false);
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'status' => 'completed', 'bill_seconds' => 21]);
        $this->assertDatabaseCount('voice_outbound_calls', 1);
    }

    public function test_wrong_channel_cannot_finish_a_call(): void
    {
        $g = $this->reserve();
        $this->event($g)->assertOk();
        preg_match('~sip:([a-f0-9]{64})@~', $g['destination'], $m);
        $this->assertFalse(app(VoiceCalling::class)->event(['event' => 'finish', 'token' => $m[1], 'channel_id' => 'different.123', 'dial_status' => 'ANSWER'])['allowed']);
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'status' => 'dialing']);
    }

    public function test_timeout_is_uncertain_and_keeps_capacity_reserved(): void
    {
        $g = $this->reserve();
        $this->event($g)->assertOk();
        DB::table('voice_outbound_calls')->where('id', $g['id'])->update(['deadline_at' => now()->subSecond()]);
        $this->getJson('/api/voice/calling')->assertOk()->assertJsonPath('calls.0.status', 'unknown');
        $this->postJson('/api/voice/calling/calls', $this->payload())->assertConflict();
        $this->postJson('/api/voice/audio/sessions', ['idempotency_key' => (string) Str::uuid()])->assertConflict();
        $this->postJson('/api/voice/calling/calls/'.$g['id'].'/cancel')->assertOk()->assertJsonPath('cancelled', false);
        $this->event($g, 'finish', ['dial_status' => 'NOANSWER', 'cause' => '19', 'bill_seconds' => 0])->assertOk();
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'status' => 'no_answer']);
        $this->assertNotNull(DB::table('voice_outbound_calls')->find($g['id'])->capacity_released_at);
    }

    public function test_unused_expired_grant_is_never_accepted(): void
    {
        $g = $this->reserve();
        DB::table('voice_outbound_calls')->where('id', $g['id'])->update(['grant_expires_at' => now()->subSecond()]);
        $this->event($g)->assertOk()->assertJsonPath('allowed', false);
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'status' => 'cancelled']);
    }

    public function test_daily_limit_and_allowlist_are_enforced(): void
    {
        config(['voice_calling_test.allowed_recipients' => ['+5511999990099']]);
        $this->postJson('/api/voice/calling/calls', $this->payload())->assertStatus(422);
        config(['voice_calling_test.allowed_recipients' => ['+5511999990001'], 'voice_calling_test.daily_limit' => 1]);
        $g = $this->reserve();
        $this->postJson('/api/voice/calling/calls/'.$g['id'].'/cancel')->assertOk();
        $this->postJson('/api/voice/calling/calls', $this->payload())->assertStatus(429);
    }

    public function test_echo_and_outbound_share_capacity(): void
    {
        $g = $this->postJson('/api/voice/audio/sessions', ['idempotency_key' => (string) Str::uuid()])->assertOk()->json();
        $this->postJson('/api/voice/calling/calls', $this->payload())->assertConflict();
        $this->postJson('/api/voice/audio/sessions/'.$g['id'].'/abandon')->assertOk();
        $this->reserve();
        $this->postJson('/api/voice/audio/sessions', ['idempotency_key' => (string) Str::uuid()])->assertConflict();
    }

    public function test_trunk_change_invalidates_pending_authorization(): void
    {
        $g = $this->reserve();
        config(['voice_calling_trunk_test.caller_id' => '+551130000099']);
        $this->event($g)->assertOk()->assertJsonPath('allowed', false);
    }

    public function test_campaign_origin_must_match_trunk(): void
    {
        $id = DB::table('voice_campaigns')->insertGetId(['workspace_id' => 1, 'name' => 'QA', 'settings' => json_encode(['business_number' => '+551130000099'])]);
        $d = $this->payload();
        $d['campaign_id'] = $id;
        $this->postJson('/api/voice/calling/calls', $d)->assertStatus(422);
        $this->assertDatabaseCount('voice_outbound_calls', 0);
    }

    public function test_private_bundle_has_separate_endpoint_and_fail_closed_dialplan(): void
    {
        $old = storage_path();
        $temp = sys_get_temp_dir().'/calling-'.Str::uuid();
        app()->useStoragePath($temp);
        try {
            app(VoiceCallingBundle::class)->generate();
            $dir = app(VoiceCallingConfig::class)->directory();
            $sip = file_get_contents($dir.'/ma-calling-pjsip.conf');
            $dial = file_get_contents($dir.'/ma-calling-extensions.conf');
            $this->assertStringContainsString('context=ma-calling-only', $sip);
            $this->assertStringContainsString('verify_server=yes', $sip);
            $this->assertStringNotContainsString('context=ma-audio-only', $sip);
            $this->assertStringContainsString('Dial(PJSIP/${MA_NUMBER}@ma-twilio-out', $dial);
            $this->assertStringNotContainsString('Dial(PJSIP/${EXTEN}', $dial);
            $this->assertStringContainsString('GOSUB_RESULT=ABORT', $dial);
            $this->assertFileDoesNotExist($dir.'/installed.json');
            $this->assertSame(0600, fileperms($dir.'/ma-calling-secret') & 0777);
        } finally {
            app()->useStoragePath($old);
            File::deleteDirectory($temp);
        }
    }

    public function test_another_user_cannot_see_or_cancel_my_reservation(): void
    {
        $g = $this->reserve();
        $other = User::factory()->create();
        $other->forceFill(['voice_workspace_id' => 1])->save();
        $this->actingAs($other);
        $this->getJson('/api/voice/calling')->assertOk()->assertJsonCount(0, 'calls');
        $this->postJson('/api/voice/calling/calls/'.$g['id'].'/cancel')->assertNotFound();
    }

    public function test_policy_change_is_blocked_while_capacity_is_reserved(): void
    {
        $this->reserve();
        $this->expectException(HttpException::class);
        app(VoiceCallingConfig::class)->save(['allowed_recipients' => ['+5511999990001'], 'daily_limit' => 10, 'max_seconds' => 30, 'ring_seconds' => 10, 'caller_id_confirmed' => true]);
    }
}
