<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Route};
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicReadTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['voice_workspace_id' => 1, 'voice_role' => 'admin']);
        $this->actingAs($this->admin);
    }

    private function integration(array $scopes): array
    {
        return $this->postJson('/api/voice/integrations', ['name' => 'Read API QA', 'scopes' => $scopes, 'list_keys' => [], 'agent_ids' => []])->assertCreated()->json();
    }

    private function conversation(int $workspace = 1): string
    {
        $sender = DB::table('wa_senders')->insertGetId(['workspace_id' => $workspace, 'provider' => 'qr', 'label' => 'QA', 'number' => '+1202'.random_int(1000000, 9999999), 'ownership' => 'external']);
        $id = (string) Str::uuid();
        DB::table('wa_conversations')->insert(['id' => $id, 'workspace_id' => $workspace, 'sender_id' => $sender, 'phone' => '+5511999990000', 'assigned_user_id' => $this->admin->id, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function message(string $conversation, array $extra = []): string
    {
        $id = (string) Str::uuid();
        $c = DB::table('wa_conversations')->find($conversation);
        DB::table('wa_messages')->insert($extra + ['id' => $id, 'workspace_id' => $c->workspace_id, 'sender_id' => $c->sender_id, 'conversation_id' => $c->id, 'direction' => 'inbound', 'provider' => 'qr', 'idempotency_key' => $id, 'request_hash' => hash('sha256', $id), 'account_sid' => 'private-account', 'from_number' => $c->phone, 'to_number' => '+12025550123', 'status' => 'received', 'body' => 'Combinar horário', 'created_at' => '2026-10-05 12:00:00', 'updated_at' => '2026-10-05 12:00:00']);
        return $id;
    }

    private function inbound(array $extra = []): string
    {
        $campaign = DB::table('voice_campaigns')->insertGetId(['workspace_id' => 1, 'name' => 'QA', 'settings' => '{}']);
        $queue = DB::table('voice_live_queues')->insertGetId(['workspace_id' => 1, 'campaign_id' => $campaign, 'name' => 'QA', 'agent_ids' => '[]']);
        $route = DB::table('voice_inbound_routes')->insertGetId(['workspace_id' => 1, 'queue_id' => $queue, 'number' => '+1202'.random_int(1000000, 9999999)]);
        $id = (string) Str::uuid();
        DB::table('voice_inbound_calls')->insert($extra + ['id' => $id, 'workspace_id' => 1, 'queue_id' => $queue, 'route_id' => $route, 'account_sid' => 'AC'.str_repeat('a', 32), 'call_sid' => 'CA'.bin2hex(random_bytes(16)), 'from_number' => '+5511999990000', 'to_number' => '+12025550123', 'status' => 'completed', 'notes' => 'Private note', 'answered_at' => '2026-10-05 12:00:10', 'capacity_released_at' => '2026-10-05 12:01:00', 'created_at' => '2026-10-05 12:00:00', 'updated_at' => now()]);
        return $id;
    }

    public function test_existing_scopes_do_not_grant_conversation_or_cost_access(): void
    {
        $this->getJson('/api/v1/conversations')->assertUnauthorized();
        $i = $this->integration(['lists:read', 'reports:read']);
        $this->withToken($i['token'])->getJson('/api/v1/conversations')->assertForbidden();
        $this->getJson('/api/v1/reports/costs?from=2026-10-01&to=2026-10-05')->assertForbidden();
        $this->getJson('/api/v1/calls/inbound?from=2026-10-01&to=2026-10-05')->assertForbidden();
        $this->assertSame(['lists:read', 'reports:read'], json_decode(DB::table('ma_integrations')->find($i['id'])->scopes, true));
        Http::assertNothingSent();
    }

    public function test_new_permissions_can_be_combined_with_existing_scopes(): void
    {
        $i = $this->integration(['lists:read', 'lists:write', 'calls:read', 'reports:read', 'events:read', 'leads:write', 'conversations:read', 'costs:read']);
        $this->withToken($i['token'])->getJson('/api/v1/conversations')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/reports/costs?from=2026-10-01&to=2026-10-05')->assertOk();
        DB::table('ma_integrations')->where('id', $i['id'])->update(['active' => false]);
        $this->getJson('/api/v1/conversations')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_messages_expose_button_identity_without_marking_read_or_exposing_credentials(): void
    {
        $i = $this->integration(['conversations:read']);
        $id = $this->conversation();
        $m = $this->message($id, ['button_id' => 'horario', 'button_label' => 'Combinar horário', 'reply_attribution' => 'exact']);
        $before = DB::table('wa_conversations')->find($id);
        $this->withToken($i['token'])->getJson('/api/v1/conversations/'.$id.'/messages')->assertOk()
            ->assertJsonPath('messages.data.0.id', $m)->assertJsonPath('messages.data.0.button_id', 'horario')
            ->assertJsonPath('messages.data.0.button_label', 'Combinar horário')->assertJsonPath('messages.data.0.reply_to_message_id', null)
            ->assertJsonMissingPath('messages.data.0.account_sid')->assertJsonMissingPath('messages.data.0.request_hash')->assertDontSee('private-account');
        $this->assertEquals($before, DB::table('wa_conversations')->find($id));
        $this->assertDatabaseCount('wa_messages', 1);
        $this->assertDatabaseCount('voice_outbound_calls', 0);
        Http::assertNothingSent();
    }

    public function test_workspace_isolation_covers_details_messages_filters_and_tokens(): void
    {
        $i = $this->integration(['conversations:read', 'calls:read', 'costs:read']);
        $w = DB::table('voice_workspaces')->insertGetId(['name' => 'Other']);
        $id = $this->conversation();
        $foreign = $this->conversation($w);
        $this->message($id);
        $this->message($id, ['workspace_id' => $w, 'body' => 'Foreign message']);
        $this->withToken($i['token'])->getJson('/api/v1/conversations')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/conversations/'.$foreign.'/messages')->assertNotFound();
        $this->getJson('/api/v1/conversations/'.$id.'/messages')->assertJsonPath('messages.total', 1)->assertDontSee('Foreign message');
        $sender = DB::table('wa_conversations')->find($foreign)->sender_id;
        $this->getJson('/api/v1/conversations?sender_id='.$sender)->assertNotFound();
        $call = $this->inbound(['workspace_id' => $w]);
        $this->getJson('/api/v1/calls/inbound/'.$call)->assertNotFound();
        $this->getJson('/api/v1/reports/costs?from=2026-10-05&to=2026-10-05')->assertJsonPath('rows.total', 1);
        DB::table('ma_integrations')->where('id', $i['id'])->update(['workspace_id' => $w]);
        $this->getJson('/api/v1/conversations')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_message_pagination_is_bounded_with_stable_ties_and_null_buttons(): void
    {
        $i = $this->integration(['conversations:read']);
        $id = $this->conversation();
        $ids = [];
        for ($n = 0; $n < 51; $n++) $ids[] = $this->message($id);
        sort($ids);
        $first = $this->withToken($i['token'])->getJson('/api/v1/conversations/'.$id.'/messages')->assertOk()->assertJsonCount(50, 'messages.data')->assertJsonPath('messages.total', 51)->assertJsonPath('messages.data.0.button_id', null)->json('messages.data');
        $second = $this->getJson('/api/v1/conversations/'.$id.'/messages?page=2')->assertJsonCount(1, 'messages.data')->json('messages.data');
        $this->assertSame($ids, array_column(array_merge($first, $second), 'id'));
        $this->getJson('/api/v1/conversations?page=0')->assertUnprocessable();
        $this->getJson('/api/v1/conversations?status=unknown')->assertUnprocessable();
    }

    public function test_inbound_period_uses_sao_paulo_and_transfer_is_one_call(): void
    {
        $i = $this->integration(['calls:read']);
        $id = $this->inbound();
        $this->inbound(['created_at' => '2026-10-05 02:59:59']);
        foreach (['transferred', 'completed'] as $status) DB::table('voice_inbound_offers')->insert(['id' => (string) Str::uuid(), 'call_id' => $id, 'user_id' => $this->admin->id, 'identity' => 'private-client', 'child_sid' => 'CA'.bin2hex(random_bytes(16)), 'status' => $status, 'created_at' => now()]);
        $this->withToken($i['token'])->getJson('/api/v1/calls/inbound?from=2026-10-05&to=2026-10-05')->assertOk()->assertJsonPath('rows.total', 1)->assertJsonPath('rows.data.0.outcome', 'answered')->assertJsonMissingPath('rows.data.0.account_sid');
        $this->getJson('/api/v1/calls/inbound/'.$id)->assertOk()->assertJsonCount(2, 'offers')->assertDontSee('private-client')->assertDontSee('Private note')->assertJsonMissingPath('offers.0.child_sid')->assertJsonMissingPath('call.account_sid');
        $this->getJson('/api/v1/calls/inbound?from=2026-01-01&to=2026-10-05')->assertUnprocessable();
        $this->getJson('/api/v1/calls/inbound?from=2026-10-05&to=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/v1/calls/inbound?from=2026-10-05&to=2026-10-05&queue_id=999999')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_costs_read_only_uses_local_values_and_keeps_unknown_qr(): void
    {
        $i = $this->integration(['costs:read']);
        $this->message($this->conversation());
        $call = $this->inbound();
        DB::table('ma_channel_costs')->insert(['workspace_id' => 1, 'channel' => 'voice_inbound', 'reference_id' => $call, 'provider_sid' => DB::table('voice_inbound_calls')->find($call)->call_sid, 'leg' => 'incoming', 'amount' => '0.012345', 'currency' => 'USD', 'synced_at' => now()]);
        $this->withToken($i['token'])->getJson('/api/v1/reports/costs?from=2026-10-05&to=2026-10-05&channel=whatsapp_qr')->assertOk()
            ->assertJsonPath('known.0.amount', '0.012345')->assertJsonPath('rows.total', 1)->assertJsonPath('rows.data.0.cost_status', 'unavailable')->assertJsonPath('rows.data.0.components', [])->assertJsonMissingPath('rows.data.0.can_sync');
        $this->assertDatabaseCount('ma_channel_costs', 1);
        $this->assertDatabaseCount('voice_outbound_calls', 0);
        $this->assertDatabaseCount('wa_messages', 1);
        $this->getJson('/api/v1/reports/costs?from=2026-10-05&to=2026-10-05&channel=invalid')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_contract_references_and_new_routes_match_required_scopes(): void
    {
        $this->get('/integrations/openapi.json')->assertOk()->assertHeader('Content-Type', 'application/json');
        $doc = json_decode(file_get_contents(resource_path('contracts/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('1.1.0', $doc['info']['version']);
        $paths = ['/calls/inbound', '/calls/inbound/{id}', '/conversations', '/conversations/{id}/messages', '/reports/costs'];
        foreach ($paths as $path) {
            $operation = $doc['paths'][$path]['get'];
            $url = '/api/v1'.str_replace('{id}', (string) Str::uuid(), $path);
            $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($url, 'GET'));
            $this->assertContains(\App\Http\Middleware\PublicIntegration::class.':'.$operation['x-required-scope'], $route->gatherMiddleware());
            $schema = basename($operation['responses']['200']['content']['application/json']['schema']['$ref']);
            $this->assertArrayHasKey($schema, $doc['components']['schemas']);
        }
        $walk = function ($value) use (&$walk, $doc) { if (!is_array($value)) return; if (isset($value['$ref'])) $this->assertArrayHasKey(basename($value['$ref']), $doc['components']['schemas']); foreach ($value as $child) $walk($child); };
        $walk($doc);
        $this->get('/integrations/guide')->assertOk()->assertSee('conversations:read')->assertSee('MA_INTEGRATION_TOKEN')->assertDontSee('<form', false);
    }
}
