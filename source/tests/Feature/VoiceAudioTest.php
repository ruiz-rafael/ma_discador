<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceAudioTest extends TestCase
{
    use RefreshDatabase;
    private User $agent;
    protected function setUp(): void
    {
        parent::setUp();
        config(['voice_audio_test' => ['enabled' => true, 'event_secret' => 'event-test-secret', 'sip_username' => 'test-user', 'sip_password' => 'test-password']]);
        Http::preventStrayRequests();
        Http::fake(['http://10.241.50.10:8088/httpstatus' => Http::response('ready', 200)]);
        $this->agent = $this->user(); $this->actingAs($this->agent);
    }
    private function user(int $workspace = 1): User
    {
        $u = User::factory()->create(); $u->forceFill(['voice_workspace_id' => $workspace])->save(); return $u;
    }
    private function create(?string $key = null)
    {
        return $this->postJson('/api/voice/audio/sessions', ['idempotency_key' => $key ?? (string) Str::uuid()]);
    }
    private function event(array $grant, string $event, string $channel = 'pbx.1', bool $valid = true)
    {
        preg_match('/sip:([a-f0-9]+)@/', $grant['destination'], $m);
        $body = json_encode(['event' => $event, 'token' => $m[1], 'channel_id' => $channel, 'duration' => 5, 'cause' => '16']);
        $stamp = (string) time();
        return $this->call('POST', '/internal/voice/audio/event', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_VOICE_TIMESTAMP' => $stamp, 'HTTP_X_VOICE_SIGNATURE' => $valid ? hash_hmac('sha256', $stamp.'.'.$body, 'event-test-secret') : 'invalid'], $body);
    }
    public function test_audio_requires_assigned_authenticated_user_and_service_health(): void
    {
        $this->getJson('/api/voice/audio')->assertOk()->assertJsonPath('ready', true)->assertJsonPath('pstn_enabled', false)->assertJsonMissingPath('password');
        $this->getJson('/api/voice/audio/authorize')->assertNoContent();
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::fake(['*' => Http::response('', 503)]); $this->create()->assertStatus(503);
        $this->actingAs(User::factory()->create())->getJson('/api/voice/audio')->assertForbidden();
        auth()->logout(); $this->getJson('/api/voice/audio/authorize')->assertUnauthorized();
    }
    public function test_diagnostic_polling_does_not_consume_call_creation_rate_limit(): void
    {
        for ($i = 0; $i < 10; $i++) $this->getJson('/api/voice/audio')->assertOk();
        $key = (string) Str::uuid();
        for ($i = 0; $i < 6; $i++) $this->create($key)->assertOk();
        $this->create($key)->assertStatus(429);
    }
    public function test_idempotent_grant_and_single_reservation_per_agent(): void
    {
        $key = (string) Str::uuid(); $first = $this->create($key)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->create($key)->assertOk()->assertJsonPath('id', $first['id'])->assertJsonPath('destination', $first['destination']);
        $this->create()->assertConflict(); $this->assertDatabaseCount('voice_audio_sessions', 1);
        $this->assertNotSame($first['destination'], DB::table('voice_audio_sessions')->first()->grant_hash);
    }
    public function test_global_two_slot_limit(): void
    {
        $this->create()->assertOk();
        $this->actingAs($this->user()); $this->create()->assertOk();
        $this->actingAs($this->user()); $this->create()->assertConflict();
        $this->travel(151)->seconds(); $this->create()->assertOk();
    }
    public function test_server_events_require_signature_and_single_use_grant(): void
    {
        $grant = $this->create()->assertOk()->json();
        $this->event($grant, 'start', valid: false)->assertUnauthorized();
        $this->event($grant, 'answered')->assertJsonPath('allowed', false);
        $this->event($grant, 'finish')->assertJsonPath('allowed', false);
        $this->event($grant, 'start')->assertJsonPath('allowed', true);
        $this->event($grant, 'start')->assertJsonPath('allowed', true);
        $this->event($grant, 'start', 'pbx.2')->assertJsonPath('allowed', false);
        $this->event($grant, 'answered')->assertJsonPath('allowed', true);
        $this->event($grant, 'finish')->assertJsonPath('allowed', true);
        $this->event($grant, 'finish')->assertJsonPath('allowed', true);
        $this->event($grant, 'answered')->assertJsonPath('allowed', false);
        $this->assertDatabaseHas('voice_audio_sessions', ['id' => $grant['id'], 'status' => 'ended', 'duration' => 5]);
        $this->assertDatabaseCount('voice_attempts', 0);
        $this->assertDatabaseCount('voice_actions', 0);
        $this->assertSame(4, DB::table('voice_audit')->where('subject_id', $grant['id'])->count());
    }
    public function test_expired_and_abandoned_grants_cannot_start_calls(): void
    {
        $grant = $this->create()->assertOk()->json(); $this->travel(61)->seconds();
        $this->event($grant, 'start')->assertJsonPath('allowed', false);
        $this->postJson('/api/voice/audio/sessions/'.$grant['id'].'/abandon')->assertOk();
        $this->event($grant, 'start')->assertJsonPath('allowed', false);
    }
    public function test_browser_cannot_end_server_call_or_access_other_users_sessions(): void
    {
        $grant = $this->create()->assertOk()->json(); $this->event($grant, 'start')->assertJsonPath('allowed', true);
        $this->postJson('/api/voice/audio/sessions/'.$grant['id'].'/abandon')->assertJsonPath('cancelled', false);
        $this->postJson('/api/voice/audio/sessions/'.$grant['id'].'/metrics', ['packets_sent' => 100, 'packets_received' => 90, 'audio_energy' => 0.5])->assertOk()->assertJsonPath('source', 'browser');
        $this->assertDatabaseHas('voice_audio_sessions', ['id' => $grant['id'], 'status' => 'connecting']);
        $this->actingAs($this->user()); $this->getJson('/api/voice/audio')->assertJsonCount(0, 'sessions');
        $this->postJson('/api/voice/audio/sessions/'.$grant['id'].'/abandon')->assertNotFound();
        $this->postJson('/api/voice/audio/sessions/'.$grant['id'].'/metrics', ['packets_sent' => 1, 'packets_received' => 1, 'audio_energy' => 0])->assertNotFound();
        $other = DB::table('voice_workspaces')->insertGetId(['name' => 'Other']);
        $this->actingAs($this->user($other)); $this->getJson('/api/voice/audio')->assertJsonCount(0, 'sessions');
    }
}
