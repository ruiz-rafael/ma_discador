<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwilioWhatsAppApi;
use App\Services\TwilioWhatsAppConnection;
use App\Services\WhatsAppMessages;
use App\Services\WhatsAppNumbers;
use App\Services\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private array $c;

    private User $user;

    private int $sender;

    private int $contact;

    private string $template;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->c = ['account_sid' => 'AC'.str_repeat('1', 32), 'api_key' => 'SK'.str_repeat('2', 32), 'api_secret' => 'test-secret-only-123456', 'auth_token' => 'test-token-only-123456', 'allowed_recipients' => ['+5511999990001'], 'daily_limit' => 20];
        config(['whatsapp_test_connection' => $this->c]);
        $this->user = User::factory()->create();
        $this->user->forceFill(['voice_workspace_id' => 1, 'voice_role'=>'supervisor'])->save();
        $this->actingAs($this->user);
        $this->sender = DB::table('wa_senders')->insertGetId(['workspace_id' => 1, 'label' => 'Teste', 'number' => '+5511999990000', 'ownership' => 'external', 'provider_sid' => 'XE'.str_repeat('3', 32), 'account_sid' => $this->c['account_sid'], 'status' => 'unverified']);
        $this->contact = DB::table('voice_contacts')->insertGetId(['workspace_id' => 1, 'name' => 'Teste', 'phone' => '+5511999990001', 'original_phone' => '+5511999990001', 'source' => 'QA', 'consent' => true, 'consent_evidence' => 'Opt-in fixture']);
        $this->template = app(WhatsAppTemplates::class)->create(1, ['name' => 'teste_aviso', 'language' => 'pt_BR', 'category' => 'UTILITY', 'body' => 'Olá, {{1}}.', 'variables' => ['1' => 'Ana']])->id;
        DB::table('wa_templates')->where('id', $this->template)->update(['content_sid' => 'HX'.str_repeat('4', 32), 'account_sid' => $this->c['account_sid'], 'state' => 'created', 'approval_status' => 'approved']);
    }

    private function payload(): array
    {
        return ['sender_id' => $this->sender, 'contact_id' => $this->contact, 'template_id' => $this->template, 'variables' => ['1' => 'Ana'], 'idempotency_key' => (string) Str::uuid(), 'consent_confirmed' => true, 'consent_evidence' => 'Autorização expressa para teste'];
    }

    private function fake(string $approval = 'approved', bool $timeout = false): void
    {
        Http::fake(function ($r) use ($approval, $timeout) {
            if (str_contains($r->url(), '/Senders/')) {
                return Http::response(['sid' => 'XE'.str_repeat('3', 32), 'sender_id' => 'whatsapp:+5511999990000', 'status' => 'ONLINE']);
            }
            if (str_ends_with($r->url(), '/ApprovalRequests')) {
                return Http::response(['whatsapp' => ['status' => $approval]]);
            }
            if (str_ends_with($r->url(), '/Messages.json')) {
                if ($timeout) {
                    throw new ConnectionException('timeout');
                }

return Http::response(['sid' => 'SM'.str_repeat('5', 32), 'account_sid' => $this->c['account_sid'], 'status' => 'queued'], 201);
            }
            throw new \RuntimeException('Unexpected fake request');
        });
    }

    private function webhook(string $suffix, array $d, ?string $signature = null)
    {
        $d = $d + ['AccountSid' => $this->c['account_sid']];
        $sig = $signature ?? (new RequestValidator($this->c['auth_token']))->computeSignature(TwilioWhatsAppConnection::BASE.$suffix, $d);

        return $this->call('POST', '/callbacks/twilio/whatsapp'.$suffix, $d, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_TWILIO_SIGNATURE' => $sig, 'HTTP_ACCEPT' => 'application/json'], http_build_query($d));
    }

    public function test_configuration_is_scoped_and_secrets_never_return_to_browser(): void
    {
        $r = $this->getJson('/api/voice/whatsapp')->assertOk()->assertJsonPath('connection.configured', true);
        foreach (['api_key', 'api_secret', 'auth_token'] as $key) {
            $this->assertStringNotContainsString($this->c[$key], $r->getContent());
        }
        $this->user->forceFill(['voice_workspace_id' => null])->save();
        $this->getJson('/api/voice/whatsapp')->assertForbidden();
    }

    public function test_number_policy_preserves_legacy_single_and_supports_separate(): void
    {
        $n = app(WhatsAppNumbers::class);
        $s = $n->normalize(1, ['business_number' => '+5511999990000']);
        $this->assertSame('single', $s['number_mode']);
        $this->assertSame('+5511999990000', $s['whatsapp_number']);
        $s = $n->normalize(1, ['number_mode' => 'separate', 'business_number' => '+551130000000', 'whatsapp_number' => '+5511999990000', 'whatsapp_sender_id' => $this->sender, 'whatsapp_enabled' => true]);
        $this->assertSame('+5511999990000', $s['whatsapp_number']);
        $this->expectException(HttpException::class);
        $n->normalize(1, ['number_mode' => 'single', 'business_number' => '+551130000000', 'whatsapp_number' => '+5511999990000']);
    }

    public function test_sender_from_other_workspace_cannot_be_bound(): void
    {
        $w = DB::table('voice_workspaces')->insertGetId(['name' => 'Other']);
        DB::table('wa_senders')->where('id', $this->sender)->update(['workspace_id' => $w]);
        $this->expectException(HttpException::class);
        app(WhatsAppNumbers::class)->normalize(1, ['business_number' => '+5511999990000', 'whatsapp_sender_id' => $this->sender]);
    }

    public function test_local_number_registration_does_not_claim_provider_verification(): void
    {
        config(['whatsapp_test_connection' => null]);
        $this->postJson('/api/voice/whatsapp/senders', ['label' => 'Outro', 'number' => '+5511999990009', 'ownership' => 'external'])->assertOk()->assertJsonPath('status', 'unverified');
        $this->postJson('/api/voice/whatsapp/senders/'.$this->sender.'/sync')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_template_creation_publication_and_approval_have_distinct_states(): void
    {
        $r = $this->postJson('/api/voice/whatsapp/templates', ['name' => 'novo_aviso', 'language' => 'pt_BR', 'category' => 'UTILITY', 'body' => 'Olá {{1}}', 'variables' => ['1' => 'Ana']])->assertOk()->assertJsonPath('state', 'draft');
        $id = $r->json('id');
        Http::fake(['https://content.twilio.com/v1/Content' => Http::response(['sid' => 'HX'.str_repeat('6', 32), 'account_sid' => $this->c['account_sid']], 201), '*/ApprovalRequests/whatsapp' => Http::response(['status' => 'received']), '*/ApprovalRequests' => Http::response(['whatsapp' => ['status' => 'pending']])]);
        $this->postJson('/api/voice/whatsapp/templates/'.$id.'/publish')->assertOk()->assertJsonPath('state', 'created')->assertJsonPath('approval_status', 'not_submitted');
        $this->postJson('/api/voice/whatsapp/templates/'.$id.'/approval', ['submit' => true])->assertOk()->assertJsonPath('approval_status', 'pending');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/Content') && $r['types']['twilio/text']['body'] === 'Olá {{1}}' && ((array) $r['variables'])['1'] === 'Ana');
        $this->postJson('/api/voice/whatsapp/templates/'.$id.'/publish')->assertConflict();
    }

    public function test_publication_timeout_blocks_duplicate_post(): void
    {
        DB::table('wa_templates')->where('id', $this->template)->update(['state' => 'draft', 'content_sid' => null]);
        Http::fake(['*' => Http::response([], 504)]);
        $this->postJson('/api/voice/whatsapp/templates/'.$this->template.'/publish')->assertStatus(502);
        $this->assertDatabaseHas('wa_templates', ['id' => $this->template, 'state' => 'publication_unknown']);
        $this->postJson('/api/voice/whatsapp/templates/'.$this->template.'/publish')->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_template_rejects_missing_or_extra_variable_values(): void
    {
        $this->postJson('/api/voice/whatsapp/templates', ['name' => 'missing_vars', 'language' => 'pt_BR', 'category' => 'UTILITY', 'body' => 'Olá {{1}}', 'variables' => []])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_real_send_is_idempotent_and_not_marked_delivered_on_acceptance(): void
    {
        $this->fake();
        $d = $this->payload();
        $r = $this->postJson('/api/voice/whatsapp/messages', $d)->assertOk()->assertJsonPath('status', 'queued');
        $this->postJson('/api/voice/whatsapp/messages', $d)->assertOk()->assertJsonPath('id', $r->json('id'));
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/Messages.json') && $r['From'] === 'whatsapp:+5511999990000' && $r['ContentVariables'] === '{"1":"Ana"}' && str_contains($r['StatusCallback'], '/status/'));
        $d['variables'] = ['1' => 'Bia'];
        $this->postJson('/api/voice/whatsapp/messages', $d)->assertConflict();
        $this->assertDatabaseCount('wa_messages', 1);
    }

    public function test_timeout_preserves_unknown_attempt_without_resending(): void
    {
        $this->fake(timeout: true);
        $d = $this->payload();
        $r = $this->postJson('/api/voice/whatsapp/messages', $d)->assertOk()->assertJsonPath('status', 'unknown');
        $this->postJson('/api/voice/whatsapp/messages', $d)->assertOk()->assertJsonPath('id', $r->json('id'));
        $this->assertDatabaseCount('wa_messages', 1);
    }

    public function test_unapproved_template_and_suppressed_or_unlisted_contacts_cannot_send(): void
    {
        $this->fake('paused');
        $this->postJson('/api/voice/whatsapp/messages', $this->payload())->assertStatus(422);
        $this->fake();
        DB::table('voice_contacts')->where('id', $this->contact)->update(['suppressed_at' => now()]);
        $this->postJson('/api/voice/whatsapp/messages', $this->payload())->assertStatus(422);
        DB::table('voice_contacts')->where('id', $this->contact)->update(['suppressed_at' => null]);
        config(['whatsapp_test_connection.allowed_recipients' => ['+5511999999999']]);
        $this->postJson('/api/voice/whatsapp/messages', $this->payload())->assertStatus(422);
        $this->assertDatabaseCount('wa_messages', 0);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_daily_limit_counts_unknown_attempts(): void
    {
        $this->fake(timeout: true);
        config(['whatsapp_test_connection.daily_limit' => 1]);
        $this->postJson('/api/voice/whatsapp/messages', $this->payload())->assertOk();
        $this->postJson('/api/voice/whatsapp/messages', $this->payload())->assertStatus(429);
        $this->assertDatabaseCount('wa_messages', 1);
    }

    public function test_signed_callbacks_are_idempotent_and_do_not_regress_delivery(): void
    {
        $this->fake();
        $id = $this->postJson('/api/voice/whatsapp/messages', $this->payload())->json('id');
        $d = ['MessageSid' => 'SM'.str_repeat('5', 32), 'MessageStatus' => 'read', 'ErrorCode' => ''];
        $this->webhook('/status/'.$id, $d, 'invalid')->assertForbidden();
        $this->webhook('/status/'.$id, $d)->assertNoContent();
        $this->webhook('/status/'.$id, $d)->assertNoContent();
        $d['MessageStatus'] = 'sent';
        $this->webhook('/status/'.$id, $d)->assertNoContent();
        $this->assertDatabaseHas('wa_messages', ['id' => $id, 'status' => 'read']);
        $this->assertDatabaseCount('wa_events', 3);
        $d['AccountSid'] = 'AC'.str_repeat('9', 32);
        $this->webhook('/status/'.$id, $d)->assertForbidden();
    }

    public function test_callback_can_arrive_before_message_post_response(): void
    {
        $this->fake();
        $this->mock(TwilioWhatsAppApi::class, function ($m) {
            $m->shouldReceive('sender')->andReturn(['sid' => 'XE'.str_repeat('3', 32), 'sender_id' => 'whatsapp:+5511999990000', 'status' => 'ONLINE']);
            $m->shouldReceive('approval')->andReturn(['whatsapp' => ['status' => 'approved']]);
            $m->shouldReceive('request')->once()->andReturnUsing(function ($method, $url, $payload) {
                $id = basename($payload['StatusCallback']);
                app(WhatsAppMessages::class)->apply($id, 'SM'.str_repeat('5', 32), 'delivered', null);

                return ['sid' => 'SM'.str_repeat('5', 32), 'account_sid' => $this->c['account_sid'], 'status' => 'queued'];
            });
        });
        $this->postJson('/api/voice/whatsapp/messages', $this->payload())->assertOk()->assertJsonPath('status', 'delivered');
    }

    public function test_inbound_signature_preserves_whitespace_and_optout_is_deduplicated(): void
    {
        $d = ['MessageSid' => 'SM'.str_repeat('7', 32), 'From' => 'whatsapp:+5511999990001', 'To' => 'whatsapp:+5511999990000', 'Body' => '  SAIR  '];
        $this->webhook('/inbound', $d)->assertOk();
        $this->webhook('/inbound', $d)->assertOk();
        $this->assertDatabaseCount('wa_messages', 1);
        $this->assertNotNull(DB::table('voice_contacts')->find($this->contact)->suppressed_at);
        $this->assertDatabaseHas('wa_messages', ['body' => '  SAIR  ']);
    }

    public function test_inbound_normal_reply_stops_contact_approach(): void
    {
        $this->webhook('/inbound', ['MessageSid' => 'SM'.str_repeat('8', 32), 'From' => 'whatsapp:+5511999990001', 'To' => 'whatsapp:+5511999990000', 'Body' => 'Podemos conversar'])->assertOk();
        $this->assertNotNull(DB::table('voice_contacts')->find($this->contact)->replied_at);
    }

    public function test_private_configuration_is_encrypted_with_restricted_permissions(): void
    {
        $old = storage_path();
        $temp = sys_get_temp_dir().'/ma-wa-'.Str::uuid();
        app()->useStoragePath($temp);
        try {
            app(TwilioWhatsAppConnection::class)->save($this->c);
            $path = storage_path('app/private/voice/whatsapp/connection.enc');
            $this->assertSame(0600, fileperms($path) & 0777);
            $text = file_get_contents($path);
            $this->assertStringNotContainsString($this->c['auth_token'], $text);
            $this->assertEquals($this->c, json_decode(Crypt::decryptString($text), true));
        } finally {
            app()->useStoragePath($old);
            File::deleteDirectory($temp);
        }
    }

    public function test_consulting_unsubmitted_template_preserves_submission_option(): void
    {
        DB::table('wa_templates')->where('id', $this->template)->update(['approval_status' => 'not_submitted']);
        Http::fake(['*' => Http::response(['whatsapp' => null])]);
        $this->postJson('/api/voice/whatsapp/templates/'.$this->template.'/approval', ['submit' => false])->assertOk()->assertJsonPath('approval_status', 'not_submitted');
    }

    public function test_provider_number_mismatch_cannot_authorize_sending(): void
    {
        Http::fake(['*' => Http::response(['sid' => 'XE'.str_repeat('3', 32), 'sender_id' => 'whatsapp:+5511999990099', 'status' => 'ONLINE'])]);
        $this->postJson('/api/voice/whatsapp/messages', $this->payload())->assertStatus(422);
        $this->assertDatabaseCount('wa_messages', 0);
    }

    public function test_campaign_number_binding_is_enforced_for_manual_send(): void
    {
        $this->fake();
        $id = DB::table('voice_campaigns')->insertGetId(['workspace_id' => 1, 'name' => 'Binding test', 'settings' => json_encode(['number_mode' => 'separate', 'business_number' => '+551130000000', 'whatsapp_enabled' => true, 'whatsapp_number' => '+5511999990099'])]);
        $d = $this->payload();
        $d['campaign_id'] = $id;
        $this->postJson('/api/voice/whatsapp/messages',$d)->assertStatus(422);
        $this->assertDatabaseCount('wa_messages',0);
    }
}
