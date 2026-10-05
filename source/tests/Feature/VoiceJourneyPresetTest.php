<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VoiceJourneyPreset;
use App\Services\VoicePersonalization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VoiceJourneyPresetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->user->forceFill(['voice_workspace_id' => 1, 'voice_role' => 'admin'])->save();
        $this->actingAs($this->user);
    }

    public function test_preset_creates_one_paused_editable_journey_without_contacts_calls_or_provider_requests(): void
    {
        $p = app(VoiceJourneyPreset::class)->create(1, $this->user->id, '+12025550123');
        $campaign = DB::table('voice_campaigns')->find($p['campaign_id']);
        $settings = json_decode($campaign->settings, true);
        $this->assertSame('paused', $campaign->status);
        $this->assertSame(5, $settings['max_attempts']);
        $this->assertSame(5, $settings['whatsapp_after']);
        $this->assertSame('{nome}', $settings['whatsapp_variables'][1]);
        $this->assertDatabaseHas('voice_live_queues', ['id' => $p['queue_id'], 'status' => 'paused']);
        $this->assertDatabaseHas('wa_templates', ['id' => $p['template_id'], 'state' => 'draft', 'approval_status' => 'not_submitted']);
        foreach (['voice_members', 'voice_list_members', 'voice_outbound_calls', 'wa_messages', 'voice_followups'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->getJson('/api/voice/journeys')->assertOk()->assertJsonPath('0.name', VoiceJourneyPreset::NAME)->assertJsonPath('0.contact_count', 0)->assertJsonPath('0.template.approval_status', 'not_submitted');
        $settings['retry_minutes'] = 15;
        $this->putJson('/api/voice/campaigns/'.$campaign->id, ['name' => 'Minha jornada revisada', 'revision' => 0, 'contact_ids' => [], 'settings' => $settings])->assertOk();
        $this->getJson('/api/voice/journeys')->assertOk()->assertJsonPath('0.retry_minutes.no_answer', 15);
        $this->assertSame([], json_decode(DB::table('voice_campaign_policies')->where('campaign_id', $campaign->id)->value('retry_minutes'), true));
        $again = app(VoiceJourneyPreset::class)->create(1, $this->user->id, '+12025550123');
        $this->assertFalse($again['created']);
        $this->assertSame($campaign->id, $again['campaign_id']);
        $this->assertDatabaseCount('voice_campaigns', 1);
        $this->assertDatabaseHas('voice_campaigns', ['id' => $campaign->id, 'name' => 'Minha jornada revisada']);
        Http::assertNothingSent();
    }

    public function test_personalization_uses_each_contact_and_never_evaluates_contact_data_as_tokens(): void
    {
        $service = app(VoicePersonalization::class);
        $campaign = (object) ['name' => 'Jornada de retorno'];
        $ana = (object) ['name' => 'Ana Souza', 'phone' => '+5511999990001', 'crm_contact_id' => 'A1', 'source' => 'Formulário'];
        $joao = (object) ['name' => 'João Silva', 'phone' => '+5511999990002', 'crm_contact_id' => 'B2', 'source' => 'Indicação'];
        $text = 'Olá, {primeiro_nome}! {nome} · {telefone} · {id_crm} · {origem} · {campanha}';
        $this->assertSame('Olá, Ana! Ana Souza · +5511999990001 · A1 · Formulário · Jornada de retorno', $service->render($text, $ana, $campaign));
        $this->assertSame('Olá, João! João Silva · +5511999990002 · B2 · Indicação · Jornada de retorno', $service->render($text, $joao, $campaign));
        $ana->name = '{telefone}';
        $this->assertSame('Olá, {telefone}', $service->render('Olá, {nome}', $ana, $campaign));
        Http::assertNothingSent();
    }

    public function test_unknown_variable_is_rejected_when_saving_and_missing_crm_value_blocks_render(): void
    {
        $p = app(VoiceJourneyPreset::class)->create(1, $this->user->id, '+12025550123');
        $c = DB::table('voice_campaigns')->find($p['campaign_id']);
        $s = json_decode($c->settings, true);
        $s['whatsapp_variables'] = ['1' => '{senha}'];
        $this->putJson('/api/voice/campaigns/'.$c->id, ['name' => $c->name, 'revision' => 0, 'contact_ids' => [], 'settings' => $s])->assertStatus(422);
        $this->expectException(HttpException::class);
        app(VoicePersonalization::class)->render('ID: {id_crm}', (object) ['name' => 'Ana'], (object) ['name' => 'Campanha']);
    }

    public function test_journey_catalog_does_not_expose_other_workspace_or_credentials(): void
    {
        app(VoiceJourneyPreset::class)->create(1, $this->user->id, '+12025550123');
        DB::table('voice_workspaces')->insert(['id' => 2, 'name' => 'Outro workspace']);
        $this->user->forceFill(['voice_workspace_id' => 2])->save();
        $this->getJson('/api/voice/journeys')->assertOk()->assertExactJson([]);
        $this->user->forceFill(['voice_workspace_id' => null])->save();
        $this->getJson('/api/voice/journeys')->assertForbidden();
        Http::assertNothingSent();
    }
}
