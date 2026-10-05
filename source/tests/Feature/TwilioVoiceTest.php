<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwilioVoiceConnection;
use App\Services\VoiceCallingConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

class TwilioVoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $contact;
    private string $parent = 'CA11111111111111111111111111111111';
    private string $child = 'CA22222222222222222222222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['voice_calling_test' => ['allowed_recipients' => ['+5511999990001'], 'daily_limit' => 20, 'max_seconds' => 30, 'ring_seconds' => 10, 'caller_id_confirmed' => true, 'sip_username' => 'ma-calling', 'sip_password' => 'sip-only-fixture-secret', 'event_secret' => str_repeat('b', 64)], 'twilio_voice_test' => ['account_sid' => 'AC'.str_repeat('1', 32), 'api_key' => 'SK'.str_repeat('2', 32), 'api_secret' => 'fixture-api-key-secret', 'auth_token' => 'fixture-auth-token-secret', 'application_sid' => 'AP'.str_repeat('3', 32), 'caller_id' => '+551130000001', 'edge' => 'sao-paulo', 'enabled' => true]]);
        $this->user = User::factory()->create();
        $this->user->forceFill(['voice_workspace_id' => 1, 'voice_role'=>'supervisor'])->save();
        $this->actingAs($this->user);
        $this->contact = DB::table('voice_contacts')->insertGetId(['workspace_id' => 1, 'name' => 'API QA', 'phone' => '+5511999990001', 'original_phone' => '+5511999990001', 'source' => 'QA fixture', 'consent' => true, 'consent_evidence' => 'Test permission']);
    }

    private function payload(): array
    {
        return ['method' => 'programmable_voice', 'contact_id' => $this->contact, 'idempotency_key' => (string) Str::uuid(), 'consent_confirmed' => true, 'consent_evidence' => 'Autorização expressa para teste'];
    }

    private function reserve(): array
    {
        return $this->postJson('/api/voice/calling/calls', $this->payload())->assertOk()->json();
    }

    private function hook(string $path, array $d, bool $signed = true)
    {
        $d = ['AccountSid' => config('twilio_voice_test.account_sid')] + $d;
        $url = TwilioVoiceConnection::BASE.$path;
        $signature = (new RequestValidator(config('twilio_voice_test.auth_token')))->computeSignature($url, $d);
        return $this->call('POST', '/callbacks/twilio/voice'.$path, $d, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TWILIO_SIGNATURE' => $signed ? $signature : 'invalid'], http_build_query($d));
    }

    private function dial(array $g, array $extra = [], bool $signed = true)
    {
        return $this->hook('/dial', array_replace($g['params'] + ['From' => 'client:'.TwilioVoiceConnection::identity($g['id']), 'CallSid' => $this->parent], $extra), $signed);
    }

    private function event(array $g, string $status, array $extra = [])
    {
        return $this->hook('/status/'.$g['id'], array_replace(['ParentCallSid' => $this->parent, 'CallSid' => $this->child, 'CallStatus' => $status], $extra));
    }

    public function test_api_reservation_without_pbx_has_short_scoped_token_and_no_server_secrets(): void
    {
        $data = $this->payload();
        $g = $this->postJson('/api/voice/calling/calls', $data)->assertOk()->json();
        $this->postJson('/api/voice/calling/calls', $data)->assertOk()->assertJsonPath('id', $g['id']);
        $jwt = json_decode(base64_decode(strtr(explode('.', $g['access_token'])[1], '-_', '+/')), true);
        $this->assertSame('ma_'.str_replace('-', '', $g['id']), $jwt['grants']['identity']);
        $this->assertSame(config('twilio_voice_test.application_sid'), $jwt['grants']['voice']['outgoing']['application_sid']);
        $this->assertFalse($jwt['grants']['voice']['incoming']['allow'] ?? false);
        $this->assertGreaterThanOrEqual(time() + 295, $jwt['exp']);
        $this->assertLessThanOrEqual(time() + 300, $jwt['exp']);
        foreach (['api_secret', 'auth_token', 'sip_password', 'event_secret'] as $secret) {
            $this->assertArrayNotHasKey($secret, $g);
        }
        $this->getJson('/api/voice/calling')->assertOk()->assertJsonPath('methods.programmable_voice.ready', true)->assertJsonPath('methods.sip_trunk.ready', false);
        $this->assertDatabaseCount('voice_outbound_calls', 1);
        Http::assertNothingSent();
    }

    public function test_both_methods_fail_closed_without_configuration_and_invalid_method_is_rejected(): void
    {
        config(['twilio_voice_test' => null]);
        $this->postJson('/api/voice/calling/calls', $this->payload())->assertStatus(503);
        $this->postJson('/api/voice/calling/calls', array_replace($this->payload(), ['method' => 'sip_trunk']))->assertStatus(503);
        $this->postJson('/api/voice/calling/calls', array_replace($this->payload(), ['method' => 'arbitrary']))->assertStatus(422);
        $this->assertDatabaseCount('voice_outbound_calls', 0);
    }

    public function test_only_signed_single_use_grant_can_emit_bounded_twiml_and_client_cannot_choose_destination(): void
    {
        $g = $this->reserve();
        $this->dial($g, signed: false)->assertForbidden();
        $xml = $this->dial($g, ['To' => '+19009999999', 'CallerId' => '+19009999999', 'Extra' => ' + preserve whitespace '])->assertOk()->getContent();
        $this->assertStringContainsString('+5511999990001', $xml);
        $this->assertStringNotContainsString('+19009999999', $xml);
        $this->assertStringContainsString('timeLimit="30"', $xml);
        $this->assertStringContainsString('timeout="10"', $xml);
        $this->assertStringContainsString('record="do-not-record"', $xml);
        $this->assertStringContainsString('/status/'.$g['id'], $xml);
        $this->assertStringNotContainsString('<Dial', $this->dial($g)->assertOk()->getContent());
        $this->assertStringNotContainsString('<Dial', $this->dial($g, ['CallSid' => 'CA'.str_repeat('9',32)])->assertOk()->getContent());
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'status' => 'dialing', 'channel_id' => $this->parent]);
    }

    public function test_wrong_identity_expired_grant_and_suppressed_contact_never_dial(): void
    {
        $g = $this->reserve();
        $this->assertStringNotContainsString('<Dial', $this->dial($g, ['From' => 'client:other'])->assertOk()->getContent());
        $this->assertStringNotContainsString('<Dial', $this->dial($g, ['Grant' => str_repeat('0',64)])->assertOk()->getContent());
        DB::table('voice_contacts')->where('id', $this->contact)->update(['suppressed_at' => now()]);
        $this->assertStringNotContainsString('<Dial', $this->dial($g)->assertOk()->getContent());
        DB::table('voice_contacts')->where('id', $this->contact)->update(['suppressed_at' => null]);
        DB::table('voice_outbound_calls')->where('id', $g['id'])->update(['grant_expires_at' => now()->subSecond()]);
        $this->assertStringNotContainsString('<Dial', $this->dial($g)->assertOk()->getContent());
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'status' => 'cancelled']);
    }

    public function test_configuration_change_or_disabled_api_invalidates_unused_authorization(): void
    {
        $g = $this->reserve();
        config(['twilio_voice_test.enabled' => false]);
        $this->assertStringNotContainsString('<Dial', $this->dial($g)->assertOk()->getContent());
        config(['twilio_voice_test.enabled' => true, 'twilio_voice_test.caller_id' => '+551130000099']);
        $this->assertStringNotContainsString('<Dial', $this->dial($g)->assertOk()->getContent());
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'status' => 'pending']);
    }

    public function test_signed_status_and_finish_are_correlated_and_out_of_order_events_do_not_regress(): void
    {
        $g = $this->reserve(); $this->dial($g)->assertOk();
        $this->event($g, 'initiated', ['ParentCallSid' => 'CA'.str_repeat('9',32)])->assertForbidden();
        $this->event($g, 'in-progress')->assertNoContent();
        $this->event($g, 'ringing')->assertNoContent();
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'status' => 'answered']);
        $this->event($g, 'completed', ['CallSid' => 'CA'.str_repeat('8',32), 'CallDuration' => '20'])->assertForbidden();
        $this->event($g, 'completed', ['CallDuration' => '20'])->assertNoContent();
        $this->event($g, 'in-progress')->assertNoContent();
        $this->hook('/finish/'.$g['id'], ['CallSid' => $this->parent, 'DialCallSid' => $this->child, 'DialCallStatus' => 'completed', 'DialCallDuration' => '20'])->assertOk();
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'status' => 'completed', 'bill_seconds' => 20]);
        $this->assertNotNull(DB::table('voice_outbound_calls')->find($g['id'])->capacity_released_at);
    }

    public function test_finish_can_confirm_failure_without_progress_callback(): void
    {
        $g = $this->reserve(); $this->dial($g)->assertOk();
        $this->hook('/finish/'.$g['id'], ['CallSid' => $this->parent, 'DialCallStatus' => 'failed', 'DialCallSid' => '', 'DialCallDuration' => '0'])->assertOk();
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'status' => 'failed']);
    }

    public function test_unknown_keeps_capacity_and_browser_cancel_cannot_claim_hangup(): void
    {
        $g = $this->reserve(); $this->dial($g)->assertOk();
        DB::table('voice_outbound_calls')->where('id',$g['id'])->update(['deadline_at' => now()->subSecond()]);
        $this->getJson('/api/voice/calling')->assertOk()->assertJsonPath('calls.0.status','unknown');
        $this->postJson('/api/voice/calling/calls/'.$g['id'].'/cancel')->assertOk()->assertJsonPath('cancelled',false);
        $this->postJson('/api/voice/calling/calls',$this->payload())->assertConflict();
        $this->assertNull(DB::table('voice_outbound_calls')->find($g['id'])->capacity_released_at);
        $this->event($g,'completed',['CallDuration'=>'25'])->assertNoContent();
    }

    public function test_reconciliation_uses_provider_evidence_and_checks_ownership(): void
    {
        $g = $this->reserve(); $this->dial($g)->assertOk();
        $account = config('twilio_voice_test.account_sid');
        Http::fake([
            'https://api.twilio.com/*/Calls/'.$this->parent.'.json' => Http::response(['sid'=>$this->parent,'account_sid'=>$account,'status'=>'completed']),
            'https://api.twilio.com/*/Calls.json*' => Http::response(['calls'=>[['sid'=>$this->child,'account_sid'=>$account,'parent_call_sid'=>$this->parent,'to'=>'+5511999990001','status'=>'completed','duration'=>'18']], 'next_page_uri'=>null]),
        ]);
        $other=User::factory()->create();$other->forceFill(['voice_workspace_id'=>1])->save();
        $this->actingAs($other)->postJson('/api/voice/calling/calls/'.$g['id'].'/reconcile')->assertNotFound();
        Http::assertNothingSent();
        $this->actingAs($this->user)->postJson('/api/voice/calling/calls/'.$g['id'].'/reconcile')->assertOk()->assertJsonPath('reconciled',true);
        $this->assertDatabaseHas('voice_outbound_calls',['id'=>$g['id'],'status'=>'completed','bill_seconds'=>18]);
        Http::assertSentCount(2);
    }

    public function test_reconciliation_failure_or_empty_child_list_does_not_release_capacity(): void
    {
        $g=$this->reserve();$this->dial($g)->assertOk();
        Http::fake(['https://api.twilio.com/*'=>Http::response([],500)]);
        $this->postJson('/api/voice/calling/calls/'.$g['id'].'/reconcile')->assertStatus(502);
        $this->assertNull(DB::table('voice_outbound_calls')->find($g['id'])->capacity_released_at);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.twilio.com/*/Calls/'.$this->parent.'.json'=>Http::response(['sid'=>$this->parent,'account_sid'=>config('twilio_voice_test.account_sid'),'status'=>'completed']),
            'https://api.twilio.com/*/Calls.json*'=>Http::response(['calls'=>[],'next_page_uri'=>null]),
        ]);
        $this->postJson('/api/voice/calling/calls/'.$g['id'].'/reconcile')->assertOk()->assertJsonPath('reconciled',false);
        $this->assertNull(DB::table('voice_outbound_calls')->find($g['id'])->capacity_released_at);
    }

    public function test_api_uses_existing_allowlist_daily_limits_and_campaign_origin(): void
    {
        config(['voice_calling_test.allowed_recipients'=>['+5511999990099']]);
        $this->postJson('/api/voice/calling/calls',$this->payload())->assertStatus(422);
        config(['voice_calling_test.allowed_recipients'=>['+5511999990001'],'voice_calling_test.daily_limit'=>1]);
        $campaign=DB::table('voice_campaigns')->insertGetId(['workspace_id'=>1,'name'=>'QA','settings'=>json_encode(['business_number'=>'+551130000099'])]);
        $this->postJson('/api/voice/calling/calls',array_replace($this->payload(),['campaign_id'=>$campaign]))->assertStatus(422);
        $g=$this->reserve();$this->postJson('/api/voice/calling/calls/'.$g['id'].'/cancel')->assertOk();
        $this->postJson('/api/voice/calling/calls',$this->payload())->assertStatus(429);
    }

    public function test_sip_event_cannot_consume_api_reservation_and_pbx_release_cannot_unlock_it(): void
    {
        $g=$this->reserve();
        $result=app(\App\Services\VoiceCalling::class)->event(['token'=>$g['params']['Grant'],'event'=>'start','channel_id'=>'fake-pbx']);
        $this->assertFalse($result['allowed']);
        $this->dial($g)->assertOk();
        DB::table('voice_outbound_calls')->where('id',$g['id'])->update(['status'=>'unknown']);
        $this->artisan('voice:calling:release',['id'=>$g['id'],'--pbx-confirmed-empty'=>true])->assertFailed();
        $this->assertNull(DB::table('voice_outbound_calls')->find($g['id'])->capacity_released_at);
    }

    public function test_credentials_are_encrypted_and_cannot_rotate_with_an_open_call(): void
    {
        $original=storage_path();$dir=sys_get_temp_dir().'/voice-api-'.Str::uuid();app()->useStoragePath($dir);
        try {
            $c=app(TwilioVoiceConnection::class);$c->save(config('twilio_voice_test'));
            $file=app(VoiceCallingConfig::class)->directory().'/programmable.enc';
            $cipher=file_get_contents($file);
            $this->assertStringNotContainsString(config('twilio_voice_test.api_secret'),$cipher);
            $plain=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($cipher),true);
            $this->assertSame(config('twilio_voice_test.api_secret'),$plain['api_secret']);
            $this->assertSame(0600,fileperms($file)&0777);
            $this->reserve();
            $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
            $c->save(config('twilio_voice_test'));
        } finally {app()->useStoragePath($original);\Illuminate\Support\Facades\File::deleteDirectory($dir);}
    }

    public function test_status_omits_secrets_and_non_original_workspace_cannot_access(): void
    {
        $text=$this->getJson('/api/voice/provider/twilio')->assertOk()->getContent();
        foreach (['api_secret','auth_token','api_key','event_secret','application_sid'] as $name) $this->assertStringNotContainsString($name,$text);
        $this->user->forceFill(['voice_workspace_id'=>null])->save();
        $this->getJson('/api/voice/calling')->assertForbidden();
        $this->postJson('/api/voice/calling/calls',$this->payload())->assertForbidden();
    }
}
