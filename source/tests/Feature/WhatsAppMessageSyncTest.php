<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppMessageSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_missed_inbound_message_is_recovered_without_sending_or_duplicating(): void
    {
        Http::preventStrayRequests();
        $account = 'AC'.str_repeat('1', 32);
        config(['whatsapp_test_connection' => ['account_sid' => $account, 'api_key' => 'SK'.str_repeat('2', 32), 'api_secret' => str_repeat('s', 32), 'auth_token' => str_repeat('t', 32), 'allowed_recipients' => ['+5511999990001'], 'daily_limit' => 20]]);
        $sender = DB::table('wa_senders')->insertGetId(['workspace_id' => 1, 'label' => 'Bossa', 'number' => '+551149330254', 'ownership' => 'twilio', 'provider' => 'twilio', 'account_sid' => $account, 'status' => 'ONLINE']);
        $contact = DB::table('voice_contacts')->insertGetId(['workspace_id' => 1, 'name' => 'Rafael', 'phone' => '+5511999990001', 'original_phone' => '+5511999990001', 'source' => 'QA', 'consent' => true]);
        User::factory()->create(['voice_workspace_id' => 1, 'voice_role' => 'admin']);
        $sid = 'SM'.str_repeat('9', 32);
        $payload = ['messages' => [[
            'sid' => $sid,
            'account_sid' => $account,
            'direction' => 'inbound',
            'from' => 'whatsapp:+5511999990001',
            'to' => 'whatsapp:+551149330254',
            'status' => 'received',
            'body' => 'Tenho Interesse',
            'date_sent' => now()->toRfc2822String(),
        ]]];
        Http::fake(['https://api.twilio.com/*/Messages.json*' => Http::response($payload)]);

        $this->artisan('voice:whatsapp:sync-messages')->assertSuccessful();
        $this->artisan('voice:whatsapp:sync-messages')->assertSuccessful();

        $this->assertDatabaseCount('wa_messages', 1);
        $this->assertDatabaseHas('wa_messages', ['provider_sid' => $sid, 'sender_id' => $sender, 'contact_id' => $contact, 'direction' => 'inbound', 'body' => 'Tenho Interesse']);
        $this->assertDatabaseHas('wa_conversations', ['sender_id' => $sender, 'phone' => '+5511999990001', 'status' => 'open']);
        $this->assertNotNull(DB::table('voice_contacts')->find($contact)->replied_at);
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_unknown_content_send_is_reconciled_by_exact_body_numbers_and_time(): void
    {
        Http::preventStrayRequests();
        $account = 'AC'.str_repeat('1', 32);
        config(['whatsapp_test_connection' => ['account_sid' => $account, 'api_key' => 'SK'.str_repeat('2', 32), 'api_secret' => str_repeat('s', 32), 'auth_token' => str_repeat('t', 32), 'allowed_recipients' => ['+5511999990001'], 'daily_limit' => 20]]);
        $sender = DB::table('wa_senders')->insertGetId(['workspace_id' => 1, 'label' => 'Bossa', 'number' => '+551149330254', 'ownership' => 'twilio', 'provider' => 'twilio', 'account_sid' => $account, 'status' => 'ONLINE']);
        $contact = DB::table('voice_contacts')->insertGetId(['workspace_id' => 1, 'name' => 'Rafael', 'phone' => '+5511999990001', 'original_phone' => '+5511999990001', 'source' => 'QA', 'consent' => true]);
        $template = (string) Str::uuid();
        DB::table('wa_templates')->insert(['id' => $template, 'workspace_id' => 1, 'name' => 'recuperacao_qa', 'language' => 'pt_BR', 'category' => 'UTILITY', 'body' => 'Olá, {{1}}.', 'variables' => '{"1":"Rafael"}', 'buttons' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::uuid();
        DB::table('wa_messages')->insert(['id' => $id, 'workspace_id' => 1, 'sender_id' => $sender, 'contact_id' => $contact, 'template_id' => $template, 'direction' => 'outbound', 'provider' => 'twilio', 'idempotency_key' => $id, 'request_hash' => hash('sha256', $id), 'account_sid' => $account, 'from_number' => '+551149330254', 'to_number' => '+5511999990001', 'status' => 'unknown', 'variables' => '{"1":"Rafael"}', 'body' => null, 'created_at' => now(), 'updated_at' => now()]);
        $sid = 'MM'.str_repeat('8', 32);
        Http::fake(function ($request) use ($account, $sid) {
            if (str_contains($request->url(), 'From=')) return Http::response(['messages' => [['sid' => $sid, 'account_sid' => $account, 'direction' => 'outbound-api', 'from' => 'whatsapp:+551149330254', 'to' => 'whatsapp:+5511999990001', 'status' => 'read', 'body' => 'Olá, Rafael.', 'date_created' => now()->toRfc2822String(), 'error_code' => null]]]);
            return Http::response(['messages' => []]);
        });

        $this->artisan('voice:whatsapp:sync-messages')->assertSuccessful();

        $this->assertDatabaseHas('wa_messages', ['id' => $id, 'provider_sid' => $sid, 'status' => 'read', 'body' => 'Olá, Rafael.']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }
}
