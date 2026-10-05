<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwilioVoiceCalling;
use App\Services\TwilioVoiceConnection;
use App\Services\VoiceCalling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $contact;

    private int $campaign;

    private array $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(15, 0));
        $this->user = User::factory()->create();
        $this->user->forceFill(['voice_workspace_id' => 1, 'voice_role' => 'admin'])->save();
        $this->actingAs($this->user);
        $this->contact = DB::table('voice_contacts')->insertGetId(['workspace_id' => 1, 'name' => 'Ana', 'phone' => '+5511999990001', 'original_phone' => '+5511999990001', 'source' => 'Fixture', 'consent' => true, 'consent_evidence' => 'Autorização de teste']);
        $this->settings = ['mode' => 'preview', 'business_number' => '+551130000001', 'number_mode' => 'separate', 'timezone' => 'UTC', 'days' => [1, 2, 3, 4, 5, 6, 7], 'start_time' => '00:00', 'end_time' => '23:59', 'max_attempts' => 4, 'retry_minutes' => 1, 'concurrency' => 1, 'whatsapp_enabled' => false, 'whatsapp_delivery' => 'simulation'];
        $this->campaign = DB::table('voice_campaigns')->insertGetId(['workspace_id' => 1, 'name' => 'Campanha QA', 'status' => 'testing', 'settings' => json_encode($this->settings), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('voice_members')->insert(['campaign_id' => $this->campaign, 'contact_id' => $this->contact]);
        config(['voice_calling_test' => ['allowed_recipients' => ['+5511999990001'], 'daily_limit' => 20, 'max_seconds' => 30, 'ring_seconds' => 10, 'caller_id_confirmed' => true, 'sip_username' => 'qa', 'sip_password' => 'qa-private', 'event_secret' => str_repeat('b', 64)], 'twilio_voice_test' => ['account_sid' => 'AC'.str_repeat('1', 32), 'api_key' => 'SK'.str_repeat('2', 32), 'api_secret' => 'fixture-api-secret', 'auth_token' => 'fixture-auth-token', 'application_sid' => 'AP'.str_repeat('3', 32), 'caller_id' => '+551130000001', 'edge' => 'sao-paulo', 'enabled' => true]]);
    }

    private function record(string $status = 'completed', array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('voice_outbound_calls')->insert(array_replace(['id' => $id, 'workspace_id' => 1, 'user_id' => $this->user->id, 'contact_id' => $this->contact, 'campaign_id' => $this->campaign, 'campaign_revision' => 0, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'grant_hash' => hash('sha256', $id), 'configuration_hash' => str_repeat('c', 64), 'destination' => '+5511999990001', 'caller_id' => '+551130000001', 'method' => 'programmable_voice', 'provider_account' => config('twilio_voice_test.account_sid'), 'status' => $status, 'max_seconds' => 30, 'ring_seconds' => 10, 'consent_evidence' => 'QA autorização', 'grant_expires_at' => now(), 'deadline_at' => now(), 'started_at' => now()->subMinutes(10), 'ended_at' => now()->subMinutes(9), 'capacity_released_at' => now()->subMinutes(9), 'created_at' => now()->subMinutes(10), 'updated_at' => now()], $extra));

        return $id;
    }

    private function policy(array $extra = []): array
    {
        return array_replace(['revision' => 0, 'list_id' => null, 'daily_per_contact' => 3, 'global_daily' => 5, 'global_total' => 20, 'technical_limit' => 3, 'expires_at' => null, 'retry_minutes' => ['no_answer' => 1, 'busy' => 1, 'failed' => 1, 'cancelled' => 1], 'origin_mode' => 'configured'], $extra);
    }

    private function savePolicy(array $extra = []): void
    {
        DB::table('voice_campaigns')->where('id', $this->campaign)->update(['status' => 'paused']);
        $this->putJson('/api/voice/operations/campaigns/'.$this->campaign.'/policy', $this->policy($extra))->assertOk();
        DB::table('voice_campaigns')->where('id', $this->campaign)->update(['status' => 'testing']);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['method' => 'programmable_voice', 'contact_id' => $this->contact, 'campaign_id' => $this->campaign, 'idempotency_key' => (string) Str::uuid(), 'consent_confirmed' => true, 'consent_evidence' => 'Autorização de teste'], $extra);
    }

    private function disposition(string $id, array $extra = [])
    {
        return $this->postJson('/api/voice/operations/calls/'.$id.'/disposition', array_replace(['revision' => 0, 'idempotency_key' => (string) Str::uuid(), 'code' => 'interested'], $extra));
    }

    private function queue(): array
    {
        $q = $this->postJson('/api/voice/operations/queues', ['name' => 'Fila QA', 'campaign_id' => $this->campaign, 'mode' => 'preview', 'strategy' => 'fifo', 'wrapup_seconds' => 30, 'agent_ids' => [$this->user->id]])->assertOk()->json();
        $this->postJson('/api/voice/operations/queues/'.$q['id'].'/status', ['status' => 'running'])->assertOk();
        $this->postJson('/api/voice/queues/presence', ['status' => 'available'])->assertNoContent();

        return $q;
    }

    private function claim(int $q)
    {
        return $this->postJson('/api/voice/operations/queues/'.$q.'/claim', ['idempotency_key' => (string) Str::uuid()]);
    }

    public function test_retry_rules_inherit_campaign_interval_and_preserve_distinct_overrides(): void
    {
        $this->savePolicy(['retry_minutes' => ['no_answer' => 1, 'busy' => 30, 'failed' => 1, 'cancelled' => 1]]);
        $this->assertSame(['busy' => 30], json_decode(DB::table('voice_campaign_policies')->where('campaign_id', $this->campaign)->value('retry_minutes'), true));
        $this->settings['retry_minutes'] = 15;
        DB::table('voice_campaigns')->where('id', $this->campaign)->update(['settings' => json_encode($this->settings)]);
        $this->getJson('/api/voice/journeys')->assertOk()->assertJsonPath('0.retry_minutes.no_answer', 15)->assertJsonPath('0.retry_minutes.busy', 30);
        $this->getJson('/api/voice/operations/catalog')->assertOk()->assertJsonPath('policies.0.default_retry_minutes', 15);
        $this->record('no_answer');
        $this->assertNotNull(app(\App\Services\VoiceEligibility::class)->reason(1, DB::table('voice_campaigns')->find($this->campaign), DB::table('voice_contacts')->find($this->contact)));
        Http::assertNothingSent();
    }

    public function test_reports_scope_filters_snapshots_and_protected_fields(): void
    {
        $id = $this->record('completed', ['context_snapshot' => json_encode(['contact_name' => 'Nome histórico', 'campaign_name' => 'Nome da campanha no momento', 'agent_name' => 'Agente original'])]);
        $other = User::factory()->create();
        $other->forceFill(['voice_workspace_id' => 1])->save();
        $this->record('busy', ['user_id' => $other->id]);
        $this->getJson('/api/voice/operations/reports?status=completed')->assertOk()->assertJsonPath('calls.total', 1)->assertJsonPath('calls.data.0.contact_name', 'Nome histórico')->assertDontSee('grant_hash')->assertDontSee('request_hash');
        $this->actingAs($other)->getJson('/api/voice/operations/reports')->assertOk()->assertJsonPath('calls.total', 1)->assertJsonPath('calls.data.0.status', 'busy');
        $this->disposition($id)->assertForbidden();
        $this->postJson('/api/voice/operations/lists', ['name' => 'No', 'source' => 'QA'])->assertForbidden();
        $other->forceFill(['voice_workspace_id' => null])->save();
        $this->getJson('/api/voice/operations/reports')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_tabulation_is_idempotent_audited_and_does_not_change_technical_status(): void
    {
        $id = $this->record();
        $key = (string) Str::uuid();
        $this->disposition($id, ['idempotency_key' => $key])->assertOk()->assertDontSee('grant_hash');
        $this->disposition($id, ['idempotency_key' => $key])->assertOk();
        $this->assertDatabaseCount('voice_dispositions', 1);
        $this->disposition($id, ['code' => 'converted'])->assertConflict();
        $this->disposition($id, ['revision' => 1, 'code' => 'converted'])->assertStatus(422);
        $this->disposition($id, ['revision' => 1, 'code' => 'converted', 'correction_reason' => 'Contrato confirmado'])->assertOk();
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $id, 'status' => 'completed', 'disposition_revision' => 2, 'disposition_code' => 'converted']);
        $this->assertDatabaseCount('voice_dispositions', 2);
        $this->getJson('/api/voice/operations/reports')->assertJsonPath('summary.human_contacts', 1)->assertJsonPath('summary.converted_contacts', 1);
        Http::assertNothingSent();
    }

    public function test_cannot_tabulate_unknown_as_human_and_optout_stops_contact(): void
    {
        $id = $this->record('no_answer');
        $this->disposition($id)->assertStatus(422);
        $unknown = $this->record('unknown');
        $this->disposition($unknown, ['code' => 'machine'])->assertStatus(422);
        $answer = $this->record();
        $this->disposition($answer, ['code' => 'opt_out'])->assertOk();
        $this->assertDatabaseHas('voice_contacts', ['id' => $this->contact, 'consent' => false]);
        $this->assertNotNull(DB::table('voice_contacts')->find($this->contact)->suppressed_at);
    }

    public function test_csv_export_escapes_formulas_and_keeps_filters(): void
    {
        DB::table('voice_contacts')->where('id', $this->contact)->update(['name' => '=DANGEROUS()']);
        $this->record();
        $r = $this->get('/api/voice/operations/export?status=completed')->assertOk();
        $content = $r->streamedContent();
        $this->assertStringContainsString("'=DANGEROUS()", $content);
        $this->assertStringContainsString('completed', $content);
        $this->assertStringNotContainsString('grant_hash', $content);
    }

    public function test_csv_preview_commit_deduplicates_and_reimport_preserves_removed_and_consent(): void
    {
        $l = $this->postJson('/api/voice/operations/lists', ['name' => 'Lista', 'source' => 'QA'])->assertOk()->json('id');
        $data = ['delimiter' => ';', 'name_column' => 'nome', 'phone_column' => 'telefone', 'source' => 'Arquivo', 'consent' => true, 'consent_evidence' => 'Autorização documentada'];
        $file = UploadedFile::fake()->createWithContent('lista.csv', "nome;telefone\nAna;+5511999990001\nNova;(11) 99999-0002\nDuplicada;11999990002\nErro;=SUM(A1)\n");
        $b = $this->post('/api/voice/operations/lists/'.$l.'/preview', $data + ['file' => $file], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('summary.valid', 2)->assertJsonPath('summary.invalid', 2)->json('id');
        $this->assertDatabaseCount('voice_list_members', 0);
        $this->postJson('/api/voice/operations/imports/'.$b.'/commit')->assertOk();
        $this->postJson('/api/voice/operations/imports/'.$b.'/commit')->assertOk();
        $this->assertDatabaseCount('voice_list_members', 2);
        $this->assertDatabaseCount('voice_contacts', 2);
        $m = DB::table('voice_list_members')->where('contact_id', $this->contact)->first();
        $this->putJson('/api/voice/operations/members/'.$m->id, ['status' => 'removed', 'reason' => 'Retirada solicitada'])->assertOk();
        DB::table('voice_contacts')->where('id', $this->contact)->update(['consent' => false, 'suppressed_at' => now()]);
        $file2 = UploadedFile::fake()->createWithContent('nova.csv', "nome;telefone\nOutra pessoa;+5511999990001\n");
        $next = $this->post('/api/voice/operations/lists/'.$l.'/preview', $data + ['file' => $file2], ['Accept' => 'application/json'])->assertOk()->json('id');
        $this->postJson('/api/voice/operations/imports/'.$next.'/commit')->assertOk();
        $this->assertDatabaseHas('voice_list_members', ['id' => $m->id, 'status' => 'removed']);
        $this->assertDatabaseHas('voice_contacts', ['id' => $this->contact, 'name' => 'Ana', 'consent' => false]);
        Http::assertNothingSent();
    }

    public function test_xlsx_reads_inline_strings_and_rejects_external_entities(): void
    {
        $l = $this->postJson('/api/voice/operations/lists', ['name' => 'XLSX', 'source' => 'QA'])->json('id');
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Contatos" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $sheet = '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>nome</t></is></c><c r="B1" t="inlineStr"><is><t>telefone</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Ana</t></is></c><c r="B2" t="inlineStr"><is><t>+5511999990001</t></is></c></row></sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $data = ['delimiter' => ';', 'name_column' => 'nome', 'phone_column' => 'telefone', 'source' => 'QA', 'consent' => false];
        $file = new UploadedFile($path, 'lista.xlsx', null, null, true);
        $this->post('/api/voice/operations/lists/'.$l.'/preview', $data + ['file' => $file], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('summary.valid', 1);
        $zip->open($path);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.$sheet);
        $zip->close();
        $this->post('/api/voice/operations/lists/'.$l.'/preview', $data + ['file' => new UploadedFile($path, 'bad.xlsx', null, null, true)], ['Accept' => 'application/json'])->assertStatus(422);
        unlink($path);
    }

    public function test_global_limit_applies_to_other_campaigns_and_manual_calls(): void
    {
        $this->savePolicy(['global_daily' => 1]);
        $this->record('no_answer');
        $other = DB::table('voice_campaigns')->insertGetId(['workspace_id' => 1, 'name' => 'Outra campanha', 'status' => 'testing', 'settings' => json_encode($this->settings)]);
        DB::table('voice_members')->insert(['campaign_id' => $other, 'contact_id' => $this->contact]);
        $this->postJson('/api/voice/calling/calls', $this->payload(['campaign_id' => $other]))->assertStatus(422);
        $this->postJson('/api/voice/calling/calls', $this->payload(['campaign_id' => null]))->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_policy_has_revision_check_and_preserves_attempts_on_edit(): void
    {
        $this->record('no_answer');
        $this->savePolicy();
        $this->assertDatabaseCount('voice_outbound_calls', 1);
        DB::table('voice_campaigns')->where('id', $this->campaign)->update(['status' => 'paused']);
        $this->putJson('/api/voice/operations/campaigns/'.$this->campaign.'/policy', $this->policy())->assertConflict();
    }

    public function test_answered_contact_exits_real_queue_and_is_never_dialed_again(): void
    {
        $this->record();
        $q = $this->queue();
        $this->claim($q['id'])->assertOk()->assertJsonPath('reservation', null);
        $this->assertDatabaseCount('voice_live_reservations', 0);
        Http::assertNothingSent();
    }

    public function test_real_reservation_excludes_manual_and_simulated_and_requires_tabulation(): void
    {
        $q = $this->queue();
        $r = $this->claim($q['id'])->assertOk()->json('reservation');
        $this->claim($q['id'])->assertConflict();
        $this->postJson('/api/voice/calling/calls', $this->payload())->assertConflict();
        $this->postJson('/api/voice/calls', ['campaign_id' => $this->campaign, 'contact_id' => $this->contact, 'idempotency_key' => (string) Str::uuid()])->assertConflict();
        $g = $this->postJson('/api/voice/calling/calls', $this->payload(['queue_reservation_id' => $r['id']]))->assertOk()->json();
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'queue_id' => $q['id']]);
        DB::table('voice_outbound_calls')->where('id', $g['id'])->update(['status' => 'completed', 'started_at' => now(), 'answered_at' => now(), 'ended_at' => now(), 'capacity_released_at' => now()]);
        $this->getJson('/api/voice/operations/queues')->assertJsonPath('current.status', 'tabulation');
        $this->claim($q['id'])->assertConflict();
        $this->disposition($g['id'])->assertOk();
        $this->getJson('/api/voice/operations/queues')->assertJsonPath('current', null);
        $this->claim($q['id'])->assertOk()->assertJsonPath('reservation',null)->assertJsonPath('retry_after',30);
        Http::assertNothingSent();
    }

    public function test_pausing_queue_or_campaign_after_reservation_blocks_provider_dial(): void
    {
        $q = $this->queue();
        $r = $this->claim($q['id'])->json('reservation');
        $g = $this->postJson('/api/voice/calling/calls', $this->payload(['queue_reservation_id' => $r['id']]))->assertOk()->json();
        $this->postJson('/api/voice/operations/queues/'.$q['id'].'/status', ['status' => 'paused'])->assertOk();
        $xml = app(TwilioVoiceCalling::class)->dial($g['params'] + ['From' => 'client:'.TwilioVoiceConnection::identity($g['id']), 'CallSid' => 'CA'.str_repeat('7', 32)], config('twilio_voice_test'));
        $this->assertStringNotContainsString('<Dial', $xml);
        $this->assertNull(DB::table('voice_outbound_calls')->find($g['id'])->started_at);
        Http::assertNothingSent();
    }

    public function test_removed_list_member_after_grant_cannot_dial(): void
    {
        $l = $this->postJson('/api/voice/operations/lists', ['name' => 'Lista', 'source' => 'QA'])->json('id');
        $m = DB::table('voice_list_members')->insertGetId(['list_id' => $l, 'contact_id' => $this->contact]);
        $this->savePolicy(['list_id' => $l]);
        $g = $this->postJson('/api/voice/calling/calls', $this->payload())->assertOk()->json();
        $this->putJson('/api/voice/operations/members/'.$m, ['status' => 'removed', 'reason' => 'Saiu da lista'])->assertOk();
        $xml = app(TwilioVoiceCalling::class)->dial($g['params'] + ['From' => 'client:'.TwilioVoiceConnection::identity($g['id']), 'CallSid' => 'CA'.str_repeat('7', 32)], config('twilio_voice_test'));
        $this->assertStringNotContainsString('<Dial', $xml);
        Http::assertNothingSent();
    }

    public function test_same_ddd_requires_verified_enabled_origin_and_keeps_idempotent_choice(): void
    {
        $this->savePolicy(['origin_mode' => 'same_ddd']);
        $this->postJson('/api/voice/calling/calls', $this->payload())->assertStatus(422);
        DB::table('voice_origins')->insert(['workspace_id' => 1, 'account_sid' => config('twilio_voice_test.account_sid'), 'provider_sid' => 'PN'.str_repeat('8', 32), 'number' => '+551130000009', 'ddd' => '11', 'kind' => 'owned', 'enabled' => true, 'verified_at' => now()]);
        $d = $this->payload();
        $g = $this->postJson('/api/voice/calling/calls', $d)->assertOk()->json();
        $this->postJson('/api/voice/calling/calls', $d)->assertOk()->assertJsonPath('id', $g['id']);
        $this->assertDatabaseHas('voice_outbound_calls', ['id' => $g['id'], 'caller_id' => '+551130000009']);
        DB::table('voice_origins')->update(['enabled' => false]);
        $xml = app(TwilioVoiceCalling::class)->dial($g['params'] + ['From' => 'client:'.TwilioVoiceConnection::identity($g['id']), 'CallSid' => 'CA'.str_repeat('7', 32)], config('twilio_voice_test'));
        $this->assertStringNotContainsString('<Dial', $xml);
        Http::assertNothingSent();
    }

    public function test_cost_sync_uses_only_gets_keeps_unknown_price_and_correlates_both_legs(): void
    {
        $parent = 'CA'.str_repeat('7', 32);
        $child = 'CA'.str_repeat('8', 32);
        $id = $this->record('completed', ['channel_id' => $parent, 'provider_child_sid' => $child]);
        Http::fake(function ($r) use ($parent, $child) {
            $sid = str_contains($r->url(), $child) ? $child : $parent;

            return Http::response(['sid' => $sid, 'account_sid' => config('twilio_voice_test.account_sid'), 'parent_call_sid' => $sid === $child ? $parent : null, 'to' => '+5511999990001', 'price' => $sid === $child ? '-0.07' : null, 'price_unit' => 'usd']);
        });
        $this->postJson('/api/voice/operations/calls/'.$id.'/costs')->assertOk()->assertJsonPath('pending', 1);
        $this->assertDatabaseHas('voice_call_costs', ['provider_sid' => $parent, 'amount' => null]);
        $this->getJson('/api/voice/operations/reports')->assertOk()->assertJsonPath('costs.0.currency', 'USD');
        Http::assertSentCount(2);
        foreach (Http::recorded() as $pair) {
            $this->assertSame('GET', $pair[0]->method());
        }
    }

    public function test_expired_reservation_can_be_reclaimed_but_unknown_call_keeps_agent_blocked(): void
    {
        $q = $this->queue();
        $r = $this->claim($q['id'])->json('reservation');
        $this->travel(4)->minutes();
        $this->postJson('/api/voice/queues/presence', ['status' => 'available']);
        $next = $this->claim($q['id'])->assertOk()->json('reservation');
        $this->assertNotSame($r['id'], $next['id']);
        $id = $this->record('unknown', ['capacity_released_at' => null]);
        DB::table('voice_live_reservations')->where('id', $next['id'])->update(['call_id' => $id, 'status' => 'calling']);
        $this->getJson('/api/voice/operations/queues')->assertJsonPath('current.status', 'calling');
        $this->claim($q['id'])->assertConflict();
    }

    public function test_sip_revalidates_policy_before_allowing_the_pbx_to_dial(): void
    {
        Http::fake(['http://10.241.50.10:8088/httpstatus' => Http::response('OK')]);
        config(['voice_calling_trunk_test' => ['account_sid' => config('twilio_voice_test.account_sid'), 'caller_id' => '+551130000001'], 'voice_calling_installed_test' => true, 'voice_audio_test' => ['enabled' => true, 'sip_password' => 'fixture-only', 'event_secret' => 'fixture-only']]);
        $this->savePolicy();
        $g = $this->postJson('/api/voice/calling/calls', $this->payload(['method' => 'sip_trunk']))->assertOk()->json();
        DB::table('voice_campaigns')->where('id', $this->campaign)->update(['status' => 'paused']);
        preg_match('/sip:([a-f0-9]{64})@/', $g['destination'], $token);
        $result = app(VoiceCalling::class)->event(['event' => 'start', 'token' => $token[1], 'channel_id' => 'qa.123']);
        $this->assertFalse($result['allowed']);
        $this->assertNull(DB::table('voice_outbound_calls')->find($g['id'])->started_at);
        foreach (Http::recorded() as $pair) {
            $this->assertSame('GET', $pair[0]->method());
        }
    }

    public function test_queue_call_reaches_mock_provider_and_enforces_wrapup_without_external_request(): void
    {
        $q = $this->queue();
        $r = $this->claim($q['id'])->json('reservation');
        $g = $this->postJson('/api/voice/calling/calls', $this->payload(['queue_reservation_id' => $r['id']]))->assertOk()->json();
        $parent = 'CA'.str_repeat('7', 32);
        $child = 'CA'.str_repeat('8', 32);
        $d = $g['params'] + ['From' => 'client:'.TwilioVoiceConnection::identity($g['id']), 'CallSid' => $parent];
        $service = app(TwilioVoiceCalling::class);
        $this->assertStringContainsString('<Dial', $service->dial($d, config('twilio_voice_test')));
        $this->assertStringNotContainsString('<Dial', $service->dial($d, config('twilio_voice_test')));
        $service->apply($g['id'], config('twilio_voice_test.account_sid'), $parent, $child, 'no-answer', 0);
        $this->getJson('/api/voice/operations/queues')->assertJsonPath('current', null);
        $this->claim($q['id'])->assertOk()->assertJsonPath('reservation',null)->assertJsonPath('retry_after',30);
        $this->assertDatabaseCount('voice_outbound_calls', 1);
        Http::assertNothingSent();
    }

    public function test_origin_sync_is_read_only_scoped_and_preserves_enabled_status(): void
    {
        $account = config('twilio_voice_test.account_sid');
        $n = ['sid' => 'PN'.str_repeat('7', 32), 'account_sid' => $account, 'phone_number' => '+551130000009', 'capabilities' => ['voice' => true]];
        Http::fake(function ($r) use ($n) {
            return Http::response(str_contains($r->url(), 'IncomingPhoneNumbers') ? ['incoming_phone_numbers' => [$n, array_replace($n, ['account_sid' => 'AC'.str_repeat('9', 32), 'phone_number' => '+551330000009'])], 'next_page_uri' => null] : ['outgoing_caller_ids' => [], 'next_page_uri' => null]);
        });
        $this->postJson('/api/voice/operations/origins/sync')->assertOk()->assertJsonPath('verified', 1);
        $this->assertDatabaseCount('voice_origins', 1);
        $o = DB::table('voice_origins')->first();
        $this->assertFalse((bool) $o->enabled);
        $this->putJson('/api/voice/operations/origins/'.$o->id, ['enabled' => true])->assertOk();
        $this->postJson('/api/voice/operations/origins/sync')->assertOk();
        $this->assertDatabaseHas('voice_origins', ['id' => $o->id, 'enabled' => true]);
        foreach (Http::recorded() as $pair) {
            $this->assertSame('GET',$pair[0]->method());
        }
    }

    public function test_import_bad_phone_returns_row_error_instead_of_aborting_batch(): void
    {
        $l = $this->postJson('/api/voice/operations/lists',['name' => 'Lista', 'source' => 'QA'])->json('id');
        $file = UploadedFile::fake()->createWithContent('lista.csv',"nome;telefone\nAna;+5511999990001\nErrado;00000000\n");
        $this->post('/api/voice/operations/lists/'.$l.'/preview',['delimiter' => ';', 'name_column' => 'nome', 'phone_column' => 'telefone', 'source' => 'QA', 'consent' => false, 'file' => $file],['Accept' => 'application/json'])->assertOk()->assertJsonPath('summary.valid',1)->assertJsonPath('summary.invalid',1);
        Http::assertNothingSent();
    }

    public function test_queue_prioritizes_untouched_contact_then_oldest_attempt(): void
    {
        $this->record('no_answer');
        $other = DB::table('voice_contacts')->insertGetId(['workspace_id'=>1,'name'=>'Bia','phone'=>'+5511999990002','original_phone'=>'+5511999990002','source'=>'QA','consent'=>true,'consent_evidence'=>'Autorização de teste']);
        DB::table('voice_members')->insert(['campaign_id'=>$this->campaign,'contact_id'=>$other]);
        $q=$this->queue();
        $rid=$this->claim($q['id'])->assertOk()->assertJsonPath('reservation.contact_id',$other)->json('reservation.id');
        $this->postJson('/api/voice/operations/reservations/'.$rid.'/cancel')->assertOk();
        $this->record('no_answer',['contact_id'=>$other,'destination'=>'+5511999990002','started_at'=>now()->subMinutes(20)]);
        $this->claim($q['id'])->assertOk()->assertJsonPath('reservation.contact_id',$other);
        Http::assertNothingSent();
    }

    public function test_empty_queue_waits_and_becomes_eligible_without_resetting_attempts(): void
    {
        $this->record('no_answer',['started_at'=>now()->subSeconds(20),'ended_at'=>now()->subSeconds(10),'capacity_released_at'=>now()->subSeconds(10)]);
        $q=$this->queue();
        $this->claim($q['id'])->assertOk()->assertJsonPath('reservation',null)->assertJsonPath('retry_after',15);
        $this->travel(45)->seconds();
        $this->claim($q['id'])->assertOk()->assertJsonPath('reservation.contact_id',$this->contact);
        $this->assertDatabaseCount('voice_outbound_calls',1);Http::assertNothingSent();
    }

    public function test_agent_access_is_operational_and_requires_assigned_reservation(): void
    {
        $q=$this->queue();
        $this->user->forceFill(['voice_role'=>'agent'])->save();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonCount(0,'contacts')->assertJsonCount(0,'journeys')->assertJsonPath('user.voice_role','agent');
        $this->getJson('/api/voice')->assertForbidden();
        $this->postJson('/api/voice/campaigns/'.$this->campaign.'/status',['status'=>'paused'])->assertForbidden();
        $this->postJson('/api/voice/operations/queues/'.$q['id'].'/status',['status'=>'paused'])->assertForbidden();
        $this->postJson('/api/contacts',['name'=>'Forbidden'])->assertForbidden();
        $this->getJson('/api/voice/operations/catalog')->assertOk()->assertJsonCount(0,'policies')->assertJsonCount(0,'origins');
        $this->getJson('/api/voice/calling')->assertOk()->assertJsonCount(0,'contacts')->assertJsonCount(0,'methods.programmable_voice.allowed_recipients');
        $this->postJson('/api/voice/calling/calls',$this->payload())->assertForbidden();
        $rid=$this->claim($q['id'])->assertOk()->json('reservation.id');
        $this->postJson('/api/voice/calling/calls',$this->payload(['queue_reservation_id'=>$rid]))->assertOk();
        Http::assertNothingSent();
    }

}
