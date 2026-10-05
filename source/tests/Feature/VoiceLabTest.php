<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\{User, CrmResource};
use App\Services\VoiceLab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceLabTest extends TestCase
{
    use RefreshDatabase;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(15, 0));
        $this->agent = User::factory()->create();
        $this->agent->forceFill(['voice_workspace_id' => 1,'voice_role'=>'supervisor'])->save();
        $this->actingAs($this->agent);
        Http::preventStrayRequests();
        CrmResource::create(['kind' => 'template', 'external_id' => 'test-template', 'name' => 'Modelo', 'channel' => 'whatsapp', 'active' => true, 'origin' => 'preparation']);
    }

    private function contact(bool $consent = true, string $phone = '11999990001'): array
    {
        return $this->postJson('/api/voice/contacts', ['name' => 'Pessoa fictícia', 'phone' => $phone, 'source' => 'Teste automatizado', 'consent' => $consent, 'consent_evidence' => $consent ? 'Registro fictício para teste' : null])->assertOk()->json('contact');
    }

    private function campaign(array $contacts, array $settings = []): array
    {
        $d = $this->postJson('/api/voice/campaigns', ['name' => 'Teste', 'contact_ids' => array_column($contacts, 'id'), 'settings' => array_merge([
            'mode' => 'preview', 'timezone' => 'America/Sao_Paulo', 'days' => [1,2,3,4,5], 'start_time' => '08:00', 'end_time' => '18:00', 'max_attempts' => 3,
            'retry_minutes' => 1, 'concurrency' => 2, 'whatsapp_enabled' => true, 'whatsapp_after' => 1, 'whatsapp_delay' => 5, 'whatsapp_template_id' => 'test-template',
        ], $settings)])->assertOk()->json();
        return $this->postJson('/api/voice/campaigns/'.$d['id'].'/status', ['status' => 'testing'])->assertOk()->json();
    }

    private function start(array $campaign, array $contact, ?string $key = null)
    {
        return $this->postJson('/api/voice/calls', ['campaign_id' => $campaign['id'], 'contact_id' => $contact['id'], 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function finish(array $call, string $outcome = 'no_answer', array $extra = [])
    {
        return $this->postJson('/api/voice/calls/'.$call['id'].'/finish', ['outcome' => $outcome] + $extra);
    }

    public function test_no_unassigned_user_or_anonymous_access_and_other_workspace_is_hidden(): void
    {
        $contact = $this->contact(); $campaign = $this->campaign([$contact]); $call = $this->start($campaign, $contact)->assertOk()->json();
        $this->finish($call)->assertOk(); $action = DB::table('voice_actions')->first();
        $other = DB::table('voice_workspaces')->insertGetId(['name' => 'Outro']);
        $u = User::factory()->create(); $u->forceFill(['voice_workspace_id' => $other,'voice_role'=>'supervisor'])->save(); $this->actingAs($u);
        $this->getJson('/api/voice')->assertOk()->assertJsonCount(0, 'contacts')->assertJsonCount(0, 'campaigns')->assertJsonCount(0, 'references')->assertJsonCount(0, 'attempts')->assertJsonCount(0, 'actions')->assertJsonCount(0, 'audit');
        $this->start($campaign, $contact)->assertNotFound();
        $this->finish($call)->assertNotFound();
        $this->postJson('/api/voice/contacts/'.$contact['id'].'/stop', ['type' => 'opt_out'])->assertNotFound();
        $this->postJson('/api/voice/actions/'.$action->id.'/simulate')->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson('/api/voice')->assertForbidden();
        auth()->logout(); $this->getJson('/api/voice')->assertUnauthorized();
    }

    public function test_repeated_start_and_finish_do_not_duplicate_or_override(): void
    {
        $this->getJson('/api/voice')->assertOk()->assertJsonPath('user_id', $this->agent->id);
        $contact = $this->contact(); $campaign = $this->campaign([$contact]);
        $call = $this->start($campaign, $contact, 'once')->assertOk()->json();
        $this->start($campaign, $contact, 'once')->assertOk()->assertJsonPath('id', $call['id']);
        $this->finish($call)->assertOk(); $this->finish($call)->assertOk(); $this->finish($call, 'busy')->assertConflict();
        $this->assertDatabaseCount('voice_attempts', 1); $this->assertDatabaseCount('voice_actions', 1);
        $this->postJson('/api/voice/campaigns/'.$campaign['id'].'/activate')->assertUnprocessable();
        $this->getJson('/api/voice')->assertJsonPath('totals.real_calls', 0)->assertJsonPath('totals.real_messages', 0);
    }

    public function test_contact_is_reserved_across_campaigns_and_retry_delay_is_enforced(): void
    {
        $contact = $this->contact(); $first = $this->campaign([$contact]); $second = $this->campaign([$contact]);
        $call = $this->start($first, $contact)->assertOk()->json();
        $other = User::factory()->create(); $other->forceFill(['voice_workspace_id' => 1,'voice_role'=>'supervisor'])->save(); $this->actingAs($other);
        $this->start($second, $contact)->assertUnprocessable();
        $this->actingAs($this->agent); $this->finish($call, 'busy')->assertOk();
        $this->start($second, $contact)->assertUnprocessable(); $this->travel(2)->minutes(); $this->start($second, $contact)->assertOk();
    }

    public function test_concurrency_cap_and_per_agent_reservation(): void
    {
        $a = $this->contact(); $b = $this->contact(true, '11999990002'); $c = $this->contact(true, '11999990003');
        $campaign = $this->campaign([$a,$b,$c]);
        $this->start($campaign, $a)->assertOk(); $this->start($campaign, $b)->assertConflict();
        foreach ([$b, $c] as $i => $contact) {
            $u = User::factory()->create(); $u->forceFill(['voice_workspace_id' => 1,'voice_role'=>'supervisor'])->save(); $this->actingAs($u);
            $result = $this->start($campaign, $contact); $i === 0 ? $result->assertOk() : $result->assertConflict();
        }
    }

    public function test_reply_and_opt_out_cancel_actions_and_block_redial(): void
    {
        foreach (['reply', 'opt_out'] as $i => $type) {
            $contact = $this->contact(true, '1199999000'.($i+1)); $campaign = $this->campaign([$contact]);
            $call = $this->start($campaign, $contact)->assertOk()->json(); $this->finish($call)->assertOk();
            $this->postJson('/api/voice/contacts/'.$contact['id'].'/stop', ['type' => $type])->assertOk();
            $this->assertDatabaseHas('voice_actions', ['contact_id' => $contact['id'], 'status' => 'cancelled']);
            $this->travel(2)->minutes(); $this->start($campaign, $contact)->assertUnprocessable();
        }
    }

    public function test_missing_consent_and_later_revocation_prevent_message_step(): void
    {
        $contact = $this->contact(false); $campaign = $this->campaign([$contact]);
        $call = $this->start($campaign, $contact)->assertOk()->json(); $this->finish($call)->assertOk();
        $this->assertDatabaseHas('voice_actions', ['status' => 'blocked']);
        $contact = $this->contact(true, '11999990002'); $campaign = $this->campaign([$contact]);
        $call = $this->start($campaign, $contact)->assertOk()->json(); $this->finish($call)->assertOk();
        $action = DB::table('voice_actions')->where('contact_id', $contact['id'])->first();
        DB::table('voice_contacts')->where('id', $contact['id'])->update(['consent' => false]);
        $this->postJson('/api/voice/actions/'.$action->id.'/simulate')->assertOk()->assertJsonPath('status', 'blocked');
    }

    public function test_cadence_is_a_simulation_not_a_delivery_and_only_once_per_day(): void
    {
        $contact = $this->contact(); $campaign = $this->campaign([$contact]);
        $call = $this->start($campaign, $contact)->assertOk()->json(); $this->finish($call)->assertOk();
        $action = DB::table('voice_actions')->first();
        $this->postJson('/api/voice/actions/'.$action->id.'/simulate')->assertOk()->assertJsonPath('status', 'simulated');
        $this->postJson('/api/voice/actions/'.$action->id.'/simulate')->assertOk()->assertJsonPath('status', 'simulated');
        $this->travel(2)->minutes(); $call = $this->start($campaign, $contact)->assertOk()->json(); $this->finish($call)->assertOk();
        $this->assertDatabaseCount('voice_actions', 1);
    }

    public function test_threshold_busy_and_outside_window_do_not_trigger_whatsapp(): void
    {
        $contact = $this->contact(); $campaign = $this->campaign([$contact], ['whatsapp_after' => 2]);
        $call = $this->start($campaign, $contact)->assertOk()->json(); $this->finish($call, 'busy')->assertOk();
        $this->travel(2)->minutes(); $call = $this->start($campaign, $contact)->assertOk()->json(); $this->finish($call)->assertOk();
        $this->assertDatabaseCount('voice_actions', 0);
        $this->travel(12)->hours(); $this->start($campaign, $contact)->assertUnprocessable();
    }

    public function test_progressive_selection_skips_suppressed_and_max_attempts(): void
    {
        $a = $this->contact(); $b = $this->contact(true, '11999990002'); $campaign = $this->campaign([$a,$b], ['mode' => 'progressive', 'max_attempts' => 1]);
        $this->postJson('/api/voice/contacts/'.$a['id'].'/stop', ['type' => 'opt_out'])->assertOk();
        $call = $this->postJson('/api/voice/calls', ['campaign_id' => $campaign['id'], 'idempotency_key' => 'next'])->assertOk()->assertJsonPath('contact_id', $b['id'])->json();
        $this->finish($call, 'busy')->assertOk(); $this->travel(2)->minutes(); $this->start($campaign, $b)->assertUnprocessable();
    }

    public function test_expired_reservation_is_released_and_late_result_rejected(): void
    {
        $contact = $this->contact(); $campaign = $this->campaign([$contact]); $call = $this->start($campaign, $contact)->assertOk()->json();
        $this->travel(11)->minutes(); $this->finish($call)->assertConflict(); $this->start($campaign, $contact)->assertOk();
        $this->assertDatabaseHas('voice_attempts', ['id' => $call['id'], 'status' => 'expired']);
    }

    public function test_callback_and_stop_are_distinct_from_call_outcome(): void
    {
        $contact = $this->contact(); $campaign = $this->campaign([$contact]); $call = $this->start($campaign, $contact)->assertOk()->json();
        $this->finish($call, 'answered')->assertUnprocessable();
        $this->finish($call, 'answered', ['qualification' => 'callback', 'callback_at' => now()->addHour()->toIso8601String()])->assertOk();
        $this->travel(2)->minutes(); $this->start($campaign, $contact)->assertUnprocessable();
        $this->travel(60)->minutes(); $this->start($campaign, $contact)->assertOk();
    }

    public function test_csv_preview_commit_dedup_and_opt_out_preservation(): void
    {
        $contact = $this->contact(); $this->postJson('/api/voice/contacts/'.$contact['id'].'/stop', ['type' => 'opt_out'])->assertOk();
        $csv = "nome;telefone\nExistente;11999990001\nNovo;11999990002\nRepetido;+5511999990002\nInválido;abc\n";
        $body = ['source' => 'Teste CSV', 'consent' => '1', 'consent_evidence' => 'Registro de teste', 'name_column' => 'nome', 'phone_column' => 'telefone', 'delimiter' => ';'];
        foreach ([0,1] as $commit) {
            $this->post('/api/voice/imports', $body + ['file' => UploadedFile::fake()->createWithContent('base.csv', $csv), 'commit' => (string) $commit], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('valid', 1)->assertJsonPath('duplicates', 2)->assertJsonPath('invalid', 1)->assertJsonPath('created', $commit);
            $this->assertDatabaseCount('voice_contacts', 1 + $commit);
        }
        $this->assertNotNull(DB::table('voice_contacts')->find($contact['id'])->suppressed_at);
        $this->assertFalse((bool) DB::table('voice_contacts')->find($contact['id'])->consent);
    }

    public function test_settings_edits_require_pause_revision_and_cancel_pending_actions(): void
    {
        $contact = $this->contact(); $campaign = $this->campaign([$contact]); $call = $this->start($campaign, $contact)->assertOk()->json(); $this->finish($call)->assertOk();
        $body = ['name' => 'Editada', 'contact_ids' => [$contact['id']], 'settings' => $campaign['settings'], 'revision' => $campaign['revision']];
        $this->putJson('/api/voice/campaigns/'.$campaign['id'], $body)->assertConflict();
        $paused = $this->postJson('/api/voice/campaigns/'.$campaign['id'].'/status', ['status' => 'paused'])->assertOk()->json();
        $this->putJson('/api/voice/campaigns/'.$campaign['id'], $body)->assertConflict();
        $body['revision'] = $paused['revision']; $this->putJson('/api/voice/campaigns/'.$campaign['id'], $body)->assertOk();
        $this->assertDatabaseHas('voice_actions', ['status' => 'cancelled']);
    }

    public function test_paused_step_stays_pending_and_template_deactivation_is_rechecked(): void
    {
        $contact = $this->contact(); $campaign = $this->campaign([$contact]); $call = $this->start($campaign, $contact)->assertOk()->json(); $this->finish($call)->assertOk();
        $action = DB::table('voice_actions')->first();
        $this->postJson('/api/voice/campaigns/'.$campaign['id'].'/status', ['status' => 'paused'])->assertOk();
        $this->postJson('/api/voice/actions/'.$action->id.'/simulate')->assertUnprocessable();
        $this->assertDatabaseHas('voice_actions', ['id' => $action->id, 'status' => 'pending']);
        $this->postJson('/api/voice/campaigns/'.$campaign['id'].'/status', ['status' => 'testing'])->assertOk();
        CrmResource::where('external_id', 'test-template')->update(['active' => false]);
        $this->postJson('/api/voice/actions/'.$action->id.'/simulate')->assertOk()->assertJsonPath('status', 'blocked');
    }
}
