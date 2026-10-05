<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VoiceJourneyPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceJourneyEditorTest extends TestCase
{
    use RefreshDatabase;
    private User $user;
    private int $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->user->forceFill(['voice_workspace_id' => 1, 'voice_role' => 'admin'])->save();
        $this->actingAs($this->user);
        $this->campaign = app(VoiceJourneyPreset::class)->create(1, $this->user->id, '+12025550123')['campaign_id'];
    }

    private function node(string $node, array $config, int $revision = 0)
    {
        return $this->putJson('/api/voice/journeys/'.$this->campaign.'/nodes/'.$node, ['revision' => $revision, 'config' => $config]);
    }

    private function positions(): array
    {
        return array_map(fn ($id) => ['id' => $id, 'x' => 200, 'y' => 300], ['entry', 'voice', 'decision', 'wait', 'message', 'exit']);
    }

    public function test_layout_persists_without_touching_execution_and_rejects_stale_or_invalid_maps(): void
    {
        $url = '/api/voice/journeys/'.$this->campaign.'/layout';
        $this->putJson($url, ['revision' => 0, 'positions' => $this->positions()])->assertOk()->assertJsonPath('revision', 1);
        $this->getJson('/api/voice/journeys')->assertOk()->assertJsonPath('0.layout.positions.0.x', 200);
        $this->assertDatabaseHas('voice_campaigns', ['id' => $this->campaign, 'revision' => 0, 'followup_revision' => 0, 'status' => 'paused']);
        $this->putJson($url, ['revision' => 0, 'positions' => $this->positions()])->assertConflict();
        $positions = $this->positions(); $positions[0]['id'] = 'execute_arbitrary';
        $this->putJson($url, ['revision' => 1, 'positions' => $positions])->assertUnprocessable();
        $positions = $this->positions(); $positions[0]['x'] = 5001;
        $this->putJson($url, ['revision' => 1, 'positions' => $positions])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_node_edits_preserve_other_rules_and_require_current_revision(): void
    {
        $this->node('voice', ['max_attempts' => 6])->assertOk()->assertJsonPath('journey.settings.max_attempts', 6)->assertJsonPath('journey.settings.whatsapp_after', 5)->assertJsonPath('journey.status', 'paused');
        $this->node('decision', ['whatsapp_after' => 6])->assertConflict();
        $this->node('decision', ['whatsapp_after' => 6], 1)->assertOk()->assertJsonPath('journey.settings.whatsapp_after', 6);
        $this->node('voice', ['max_attempts' => 4], 2)->assertUnprocessable();
        $this->assertDatabaseHas('voice_campaigns', ['id' => $this->campaign, 'revision' => 2]);
        $this->assertDatabaseCount('voice_outbound_calls', 0);
        $this->assertDatabaseCount('wa_messages', 0);
        Http::assertNothingSent();
    }

    public function test_voice_card_can_explicitly_save_attempts_and_whatsapp_threshold_atomically(): void
    {
        $this->node('voice', ['max_attempts' => 1, 'end_time' => '22:00'])->assertUnprocessable();
        $this->assertDatabaseHas('voice_campaigns', ['id' => $this->campaign, 'revision' => 0]);
        $this->node('voice', ['max_attempts' => 1, 'whatsapp_after' => 1, 'end_time' => '22:00'])
            ->assertOk()->assertJsonPath('journey.settings.max_attempts', 1)
            ->assertJsonPath('journey.settings.whatsapp_after', 1)
            ->assertJsonPath('journey.settings.end_time', '22:00')->assertJsonPath('journey.status', 'paused');
        $this->assertDatabaseHas('voice_campaigns', ['id' => $this->campaign, 'revision' => 1, 'followup_revision' => 1]);
        $this->node('voice', ['max_attempts' => 2, 'whatsapp_after' => 3], 1)->assertUnprocessable();
        $this->assertSame(1, json_decode(DB::table('voice_campaigns')->where('id', $this->campaign)->value('settings'), true)['max_attempts']);
        $this->assertDatabaseCount('voice_outbound_calls', 0);
        $this->assertDatabaseCount('wa_messages', 0);
        Http::assertNothingSent();
    }

    public function test_wait_card_updates_effective_interval_and_preserves_other_outcome_overrides(): void
    {
        DB::table('voice_campaign_policies')->where('campaign_id', $this->campaign)->update(['retry_minutes' => json_encode(['no_answer' => 80, 'busy' => 30])]);
        $this->node('wait', ['retry_minutes' => 15])->assertOk()->assertJsonPath('journey.retry_minutes.no_answer', 15)->assertJsonPath('journey.retry_minutes.busy', 30);
        $this->assertSame(['busy' => 30], json_decode(DB::table('voice_campaign_policies')->where('campaign_id', $this->campaign)->value('retry_minutes'), true));
    }

    public function test_entry_replaces_members_from_list_and_preserves_list_exclusions(): void
    {
        $list = DB::table('voice_lists')->insertGetId(['workspace_id' => 1, 'name' => 'Nova lista', 'source' => 'Teste']);
        $ids = [];
        foreach (['active', 'removed'] as $i => $status) {
            $ids[] = $id = DB::table('voice_contacts')->insertGetId(['workspace_id' => 1, 'name' => 'Contato '.$i, 'phone' => '+551199999000'.$i, 'original_phone' => '+551199999000'.$i, 'source' => 'Teste', 'consent' => true, 'consent_evidence' => 'Autorização de teste']);
            DB::table('voice_list_members')->insert(['list_id' => $list, 'contact_id' => $id, 'status' => $status]);
        }
        DB::table('voice_members')->insert(['campaign_id' => $this->campaign, 'contact_id' => $ids[1]]);
        $this->node('entry', ['list_id' => $list])->assertOk()->assertJsonPath('journey.contact_count', 1)->assertJsonPath('journey.list.id', $list);
        $this->assertDatabaseHas('voice_members', ['campaign_id' => $this->campaign, 'contact_id' => $ids[0]]);
        $this->assertDatabaseMissing('voice_members', ['campaign_id' => $this->campaign, 'contact_id' => $ids[1]]);
        $this->assertDatabaseHas('voice_list_members', ['list_id' => $list, 'contact_id' => $ids[1], 'status' => 'removed']);
        $this->node('entry', ['list_id' => null, 'contact_ids' => [$ids[1]]], 1)->assertOk()->assertJsonPath('journey.list', null);
        $this->assertDatabaseCount('voice_members', 1);
        Http::assertNothingSent();
    }

    public function test_message_card_validates_variables_and_does_not_submit_or_send(): void
    {
        $this->node('message', ['whatsapp_variables' => ['1' => '{senha}']])->assertUnprocessable();
        $this->node('message', ['whatsapp_variables' => ['1' => '{primeiro_nome}']])->assertOk()->assertJsonPath('journey.settings.whatsapp_variables.1', '{primeiro_nome}')->assertJsonPath('journey.template.approval_status', 'not_submitted');
        $this->assertDatabaseCount('wa_messages', 0);
        Http::assertNothingSent();
    }

    public function test_cannot_disable_exit_or_patch_fields_from_another_card(): void
    {
        $this->node('exit', ['enabled' => false])->assertUnprocessable();
        $this->node('voice', ['whatsapp_sender_id' => 1])->assertUnprocessable();
        $this->node('voice', ['max_attempts' => 0])->assertUnprocessable();
        $this->assertDatabaseHas('voice_campaigns', ['id' => $this->campaign, 'revision' => 0]);
    }

    public function test_edit_requires_supervision_and_workspace_scope_and_paused_campaign(): void
    {
        $this->user->forceFill(['voice_role' => 'agent'])->save();
        $this->node('wait', ['retry_minutes' => 10])->assertForbidden();
        $this->putJson('/api/voice/journeys/'.$this->campaign.'/layout', ['revision' => 0, 'positions' => $this->positions()])->assertForbidden();
        DB::table('voice_workspaces')->insert(['id' => 2, 'name' => 'Outro workspace']);
        $this->user->forceFill(['voice_role' => 'admin', 'voice_workspace_id' => 2])->save();
        $this->node('wait', ['retry_minutes' => 10])->assertNotFound();
        $this->putJson('/api/voice/journeys/'.$this->campaign.'/layout', ['revision' => 0, 'positions' => $this->positions()])->assertNotFound();
        $this->user->forceFill(['voice_workspace_id' => 1])->save();
        DB::table('voice_campaigns')->where('id', $this->campaign)->update(['status' => 'testing']);
        $this->node('wait', ['retry_minutes' => 10])->assertConflict();
    }

    public function test_running_queue_prevents_mode_edit_and_rolls_back_campaign_changes(): void
    {
        DB::table('voice_live_queues')->where('campaign_id', $this->campaign)->update(['status' => 'running']);
        $this->node('voice', ['mode' => 'preview', 'max_attempts' => 6])->assertConflict();
        $this->assertDatabaseHas('voice_campaigns', ['id' => $this->campaign, 'revision' => 0, 'followup_revision' => 0]);
        $this->assertSame(5, json_decode(DB::table('voice_campaigns')->where('id', $this->campaign)->value('settings'), true)['max_attempts']);
    }
}
