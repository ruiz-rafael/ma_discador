<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WhatsAppTemplates;
use App\Services\TwilioWhatsAppConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

class WhatsAppTemplateButtonsTest extends TestCase
{
    use RefreshDatabase;
    private string $account;
    private User $admin;
    private array $calls = [];
    private string $failure = '';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->account = 'AC'.str_repeat('1', 32);
        config(['whatsapp_test_connection' => ['account_sid' => $this->account, 'api_key' => 'SK'.str_repeat('2', 32), 'api_secret' => 'fixture-secret-1234', 'auth_token' => 'fixture-token-1234', 'allowed_recipients' => ['+5511999990001'], 'daily_limit' => 10]]);
        $this->admin = User::factory()->create(['voice_role' => 'admin', 'voice_workspace_id' => 1]);
        $this->actingAs($this->admin);
    }

    private function draft(array $extra = []): array
    {
        return array_replace(['name' => 'imoveis_itaim', 'language' => 'pt_BR', 'category' => 'MARKETING', 'body' => 'Olá, {{1}}. Gostaria de conhecer os imóveis?', 'variables' => ['1' => 'Rafael'], 'buttons' => [['type' => 'reply', 'title' => 'Tenho interesse', 'action' => 'reply'], ['type' => 'reply', 'title' => 'Não receber ofertas', 'action' => 'opt_out']]], $extra);
    }

    private function create(array $extra = []): string
    {
        return $this->postJson('/api/voice/whatsapp/templates', $this->draft($extra))->assertOk()->json('id');
    }

    private function provider(): void
    {
        Http::fake(function ($r) {
            $this->calls[] = ['method' => $r->method(), 'url' => $r->url(), 'data' => $r->data()];
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/Content')) {
                if ($this->failure === 'create') throw new ConnectionException('fixture timeout');
                return Http::response(['sid' => 'HX'.str_repeat('4', 32), 'account_sid' => $this->account], 201);
            }
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/ApprovalRequests/whatsapp')) {
                if ($this->failure === 'submit') throw new ConnectionException('fixture timeout');
                return Http::response(['status' => 'received'], 201);
            }
            if ($r->method() === 'GET' && str_ends_with($r->url(), '/ApprovalRequests')) return Http::response(['whatsapp' => ['status' => 'pending']]);
            throw new \RuntimeException('Unexpected provider call');
        });
    }

    public function test_draft_is_local_editable_and_lists_buttons_for_tenant_admin(): void
    {
        $id = $this->create();
        $this->getJson('/api/voice/whatsapp')->assertOk()->assertJsonPath('templates.0.buttons.1.action', 'opt_out');
        $this->putJson('/api/voice/whatsapp/templates/'.$id, $this->draft(['body' => 'Olá, {{1}}. Novas opções no Itaim.']))->assertOk();
        $this->assertDatabaseHas('wa_templates', ['id' => $id, 'state' => 'draft', 'body' => 'Olá, {{1}}. Novas opções no Itaim.']);
        Http::assertNothingSent();
    }

    public function test_single_submit_publishes_quick_replies_and_requests_meta_approval_once(): void
    {
        $id = $this->create(); $this->provider();
        $url = '/api/voice/whatsapp/templates/'.$id.'/submit';
        $this->postJson($url, ['confirmed' => false])->assertUnprocessable();
        Http::assertNothingSent();
        $this->postJson($url, ['confirmed' => true])->assertOk()->assertJsonPath('approval_status', 'pending');
        $this->assertSame(['POST', 'POST', 'GET'], array_column($this->calls, 'method'));
        $type = $this->calls[0]['data']['types']['twilio/quick-reply'];
        $this->assertSame('Tenho interesse', $type['actions'][0]['title']);
        $this->assertSame('discai_reply:'.$id.':0', $type['actions'][0]['id']);
        $this->assertSame('discai_opt_out', $type['actions'][1]['id']);
        $this->assertSame('MARKETING', $this->calls[1]['data']['category']);
        $this->postJson($url, ['confirmed' => true])->assertOk();
        $this->assertCount(2, array_filter($this->calls, fn ($r) => $r['method'] === 'POST'));
        $this->putJson('/api/voice/whatsapp/templates/'.$id, $this->draft())->assertConflict();
        $this->assertDatabaseCount('wa_messages', 0);
    }

    public function test_link_and_phone_publish_documented_actions(): void
    {
        $id = $this->create(['buttons' => [['type' => 'url', 'title' => 'Ver imóveis', 'url' => 'https://example.com/imoveis'], ['type' => 'phone', 'title' => 'Falar com a Bossa', 'phone' => '+551149330254']]]);
        $this->provider();
        $this->postJson('/api/voice/whatsapp/templates/'.$id.'/submit', ['confirmed' => true])->assertOk();
        $this->assertSame([['type' => 'URL', 'title' => 'Ver imóveis', 'url' => 'https://example.com/imoveis'], ['type' => 'PHONE_NUMBER', 'title' => 'Falar com a Bossa', 'phone' => '+551149330254']], $this->calls[0]['data']['types']['twilio/call-to-action']['actions']);
    }

    public function test_existing_text_templates_keep_their_content_type(): void
    {
        $id = $this->create(['buttons' => []]); $this->provider();
        $this->postJson('/api/voice/whatsapp/templates/'.$id.'/submit', ['confirmed' => true])->assertOk();
        $this->assertSame(['twilio/text' => ['body' => $this->draft()['body']]], $this->calls[0]['data']['types']);
    }

    public function test_validation_blocks_unsupported_buttons_before_provider_calls(): void
    {
        $reply = ['type' => 'reply', 'title' => 'Interesse', 'action' => 'reply'];
        foreach ([
            [$reply, ['type' => 'url', 'title' => 'Site', 'url' => 'https://example.com']],
            [array_replace($reply, ['title' => str_repeat('a', 21)])],
            [array_replace($reply, ['title' => '{{1}}'])],
            [$reply, $reply],
            [['type' => 'url', 'title' => 'Site', 'url' => 'javascript:alert(1)']],
            [['type' => 'url', 'title' => 'Site', 'url' => 'https://example.com/{{1}}']],
            [['type' => 'phone', 'title' => 'Ligar', 'phone' => '11999990000']],
            [array_replace($reply, ['action' => 'execute'])],
            [['type' => 'reply', 'title' => 'Parar', 'action' => 'opt_out'], ['type' => 'reply', 'title' => 'Sair', 'action' => 'opt_out']],
        ] as $buttons) $this->postJson('/api/voice/whatsapp/templates', $this->draft(['buttons' => $buttons]))->assertUnprocessable();
        $this->postJson('/api/voice/whatsapp/templates', $this->draft(['body' => str_repeat('a', 641), 'variables' => [], 'buttons' => [['type' => 'url', 'title' => 'Site', 'url' => 'https://example.com']]]))->assertUnprocessable();
        $this->assertDatabaseCount('wa_templates', 0); Http::assertNothingSent();
    }

    public function test_timeout_does_not_create_duplicate_content_or_repeat_uncertain_approval(): void
    {
        $id = $this->create(); $this->provider(); $this->failure = 'create';
        $this->postJson('/api/voice/whatsapp/templates/'.$id.'/submit', ['confirmed' => true])->assertStatus(502);
        $this->postJson('/api/voice/whatsapp/templates/'.$id.'/submit', ['confirmed' => true])->assertConflict();
        $this->assertCount(1, $this->calls);
        $id = $this->create(['name' => 'second_template']); $this->failure = 'submit';
        $this->postJson('/api/voice/whatsapp/templates/'.$id.'/submit', ['confirmed' => true])->assertStatus(502);
        $this->assertDatabaseHas('wa_templates', ['id' => $id, 'state' => 'created', 'approval_status' => 'submission_unknown']);
        $before = count($this->calls);
        $this->postJson('/api/voice/whatsapp/templates/'.$id.'/submit', ['confirmed' => true])->assertOk();
        $this->assertCount($before + 1, $this->calls);
        $this->assertSame('GET', end($this->calls)['method']);
    }

    public function test_reconciliation_checks_button_targets_and_actions_not_only_text(): void
    {
        $id = $this->create(); $sid = 'HX'.str_repeat('6', 32);
        DB::table('wa_templates')->where('id', $id)->update(['state' => 'publication_unknown', 'account_sid' => $this->account]);
        $t = app(WhatsAppTemplates::class)->get(1, $id);
        $remote = ['sid' => $sid, 'account_sid' => $this->account, 'friendly_name' => $t->name, 'language' => $t->language, 'variables' => ['1' => 'Rafael'], 'types' => app(WhatsAppTemplates::class)->types($t)];
        $wrong = $remote; $wrong['types']['twilio/quick-reply']['actions'][1]['id'] = 'different_action';
        Http::fake(['*' => Http::sequence()->push($wrong)->push($remote)]);
        $this->postJson('/api/voice/whatsapp/templates/'.$id.'/reconcile', ['content_sid' => $sid])->assertUnprocessable();
        $this->postJson('/api/voice/whatsapp/templates/'.$id.'/reconcile', ['content_sid' => $sid])->assertOk()->assertJsonPath('state', 'created');
    }

    public function test_tenant_isolation_and_non_admin_access(): void
    {
        $id = $this->create();
        $w = DB::table('voice_workspaces')->insertGetId(['name' => 'Other']);
        DB::table('wa_templates')->where('id', $id)->update(['workspace_id' => $w]);
        $this->putJson('/api/voice/whatsapp/templates/'.$id, $this->draft())->assertNotFound();
        $this->postJson('/api/voice/whatsapp/templates/'.$id.'/submit', ['confirmed' => true])->assertNotFound();
        $this->admin->forceFill(['voice_role' => 'agent'])->save();
        $this->postJson('/api/voice/whatsapp/templates', $this->draft())->assertForbidden();
        $this->postJson('/api/voice/whatsapp/templates/'.$id.'/submit', ['confirmed' => true])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_reply_button_links_to_original_message_and_keeps_contact_subscribed(): void
    {
        $templateId = $this->create(); $service = app(WhatsAppTemplates::class);
        $buttons = $service->messageButtons($service->get(1, $templateId));
        $sender = DB::table('wa_senders')->insertGetId(['workspace_id' => 1, 'label' => 'Bossa', 'number' => '+551149330254', 'ownership' => 'twilio', 'provider' => 'twilio', 'account_sid' => $this->account]);
        $contact = DB::table('voice_contacts')->insertGetId(['workspace_id' => 1, 'name' => 'Fixture', 'phone' => '+5511999990001', 'original_phone' => '+5511999990001', 'source' => 'QA', 'consent' => true]);
        $original = (string) \Illuminate\Support\Str::uuid(); $sid = 'SM'.str_repeat('8', 32);
        DB::table('wa_messages')->insert(['id' => $original, 'workspace_id' => 1, 'sender_id' => $sender, 'contact_id' => $contact, 'direction' => 'outbound', 'provider' => 'twilio', 'account_sid' => $this->account, 'provider_sid' => $sid, 'idempotency_key' => $original, 'request_hash' => hash('sha256', $original), 'from_number' => '+551149330254', 'to_number' => '+5511999990001', 'status' => 'delivered', 'interactive' => json_encode(['mode' => 'official_template', 'buttons' => $buttons]), 'created_at' => now()->subMinute(), 'updated_at' => now()]);
        $d = ['AccountSid' => $this->account, 'MessageSid' => 'SM'.str_repeat('9', 32), 'From' => 'whatsapp:+5511999990001', 'To' => 'whatsapp:+551149330254', 'Body' => 'Tenho interesse', 'ButtonText' => 'Tenho interesse', 'ButtonPayload' => $buttons[0]['id'], 'OriginalRepliedMessageSid' => $sid];
        $sign = (new RequestValidator('fixture-token-1234'))->computeSignature(TwilioWhatsAppConnection::BASE.'/inbound', $d);
        $this->call('POST', '/callbacks/twilio/whatsapp/inbound', $d, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_TWILIO_SIGNATURE' => $sign], http_build_query($d))->assertOk();
        $this->assertDatabaseHas('wa_messages', ['provider_sid' => $d['MessageSid'], 'reply_to_message_id' => $original, 'reply_attribution' => 'context', 'button_id' => $buttons[0]['id'], 'button_label' => 'Tenho interesse']);
        $this->assertNotNull(DB::table('voice_contacts')->find($contact)->replied_at);
        $this->assertNull(DB::table('voice_contacts')->find($contact)->suppressed_at);
        Http::assertNothingSent();
    }

    public function test_signed_opt_out_button_is_recorded_deduplicated_and_scoped_to_company(): void
    {
        DB::table('wa_senders')->insert(['workspace_id' => 1, 'label' => 'Bossa', 'number' => '+551149330254', 'ownership' => 'twilio', 'provider' => 'twilio', 'account_sid' => $this->account]);
        $contact = ['workspace_id' => 1, 'name' => 'Fixture', 'phone' => '+5511999990001', 'original_phone' => '+5511999990001', 'source' => 'QA', 'consent' => true];
        $one = DB::table('voice_contacts')->insertGetId($contact);
        $other = DB::table('voice_workspaces')->insertGetId(['name' => 'Other']);
        $foreign = DB::table('voice_contacts')->insertGetId(array_replace($contact, ['workspace_id' => $other]));
        $d = ['AccountSid' => $this->account, 'MessageSid' => 'SM'.str_repeat('7', 32), 'From' => 'whatsapp:+5511999990001', 'To' => 'whatsapp:+551149330254', 'Body' => 'Não receber ofertas', 'ButtonText' => 'Não receber ofertas', 'ButtonPayload' => 'discai_opt_out'];
        $url = '/callbacks/twilio/whatsapp/inbound';
        $sign = (new RequestValidator('fixture-token-1234'))->computeSignature(TwilioWhatsAppConnection::BASE.'/inbound', $d);
        $this->call('POST', $url, $d, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_TWILIO_SIGNATURE' => 'invalid'], http_build_query($d))->assertForbidden();
        $this->assertNull(DB::table('voice_contacts')->find($one)->suppressed_at);
        for ($i = 0; $i < 2; $i++) $this->call('POST', $url, $d, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_TWILIO_SIGNATURE' => $sign], http_build_query($d))->assertOk();
        $this->assertDatabaseCount('wa_messages', 1);
        foreach ([$one] as $id) $this->assertNotNull(DB::table('voice_contacts')->find($id)->suppressed_at);
        $this->assertNull(DB::table('voice_contacts')->find($foreign)->suppressed_at);
        $interactive = json_decode(DB::table('wa_messages')->value('interactive'), true);
        $this->assertSame('button_reply', $interactive['kind']);
        $this->assertSame('discai_opt_out', $interactive['id']);
        Http::assertNothingSent();
    }
}
