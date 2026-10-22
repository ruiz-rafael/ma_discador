<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppTemplateApprovalSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['discai.portal' => false, 'whatsapp_test_connection' => [
            'account_sid' => 'AC'.str_repeat('1', 32), 'api_key' => 'SK'.str_repeat('2', 32), 'api_secret' => 'fixture-secret',
        ]]);
    }

    private function template(array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('wa_templates')->insert(array_replace([
            'id' => $id, 'workspace_id' => 1, 'name' => 'fixture_'.str_replace('-', '', $id),
            'language' => 'pt_BR', 'category' => 'UTILITY', 'body' => 'Olá, {{1}}.', 'variables' => '{"1":"Rafael"}',
            'state' => 'created', 'approval_status' => 'pending', 'account_sid' => 'AC'.str_repeat('1', 32),
            'content_sid' => 'HX'.str_replace('-', '', $id), 'synced_at' => now()->subMinutes(11),
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
        return $id;
    }

    public function test_periodic_read_updates_pending_status_and_category_without_submitting_or_sending(): void
    {
        $id = $this->template();
        Http::fake(['content.twilio.com/*' => Http::response(['whatsapp' => ['status' => 'approved', 'category' => 'MARKETING']])]);
        $this->artisan('voice:whatsapp:sync-templates')->assertSuccessful();
        $this->assertDatabaseHas('wa_templates', ['id' => $id, 'approval_status' => 'approved', 'category' => 'MARKETING']);
        $this->assertDatabaseCount('wa_messages', 0);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_ends_with($r->url(), '/ApprovalRequests'));
        $this->artisan('voice:whatsapp:sync-templates')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_skips_drafts_other_accounts_workspaces_and_recent_queries(): void
    {
        $this->template(['state' => 'draft', 'content_sid' => null]);
        $this->template(['approval_status' => 'not_submitted']);
        $this->template(['account_sid' => 'AC'.str_repeat('3', 32)]);
        DB::table('voice_workspaces')->insert(['id' => 2, 'name' => 'Other workspace', 'created_at' => now(), 'updated_at' => now()]);
        $this->template(['workspace_id' => 2]);
        $this->template(['synced_at' => now()->subMinutes(5)]);
        $this->template(['approval_status' => 'rejected']);
        $this->artisan('voice:whatsapp:sync-templates')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_keeps_state_on_provider_failure_and_continues_other_templates(): void
    {
        $bad = $this->template(); $good = $this->template(['approval_status' => 'submission_unknown', 'synced_at' => null]);
        $before = DB::table('wa_templates')->where('id', $bad)->first();
        Http::fake(fn ($r) => str_contains($r->url(), $before->content_sid)
            ? Http::response(['code' => 20429], 429)
            : Http::response(['whatsapp' => ['status' => 'rejected', 'rejection_reason' => 'Example reason']]));
        $this->artisan('voice:whatsapp:sync-templates')->assertFailed();
        $this->assertDatabaseHas('wa_templates', ['id' => $bad, 'approval_status' => 'pending', 'synced_at' => $before->synced_at]);
        $this->assertDatabaseHas('wa_templates', ['id' => $good, 'approval_status' => 'rejected', 'rejection_reason' => 'Example reason']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_no_queries_for_portal_or_unconfigured_tenant(): void
    {
        $this->template();
        config(['discai.portal' => true]);
        $this->artisan('voice:whatsapp:sync-templates')->assertSuccessful();
        config(['discai.portal' => false, 'whatsapp_test_connection' => null]);
        $this->artisan('voice:whatsapp:sync-templates')->assertSuccessful();
        Http::assertNothingSent();
    }
}
