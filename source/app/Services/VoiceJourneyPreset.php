<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Creates local, paused configuration only. Never invokes a provider or enrolls contacts. */
class VoiceJourneyPreset
{
    public const NAME = 'Zyrex | 5 tentativas → WhatsApp personalizado';

    public const TEMPLATE = 'zyrex_retorno_apos_5_tentativas';

    public const TEXT = 'Olá, {nome}! Aqui é a equipe Zyrex. Tentamos falar com você por telefone. Qual é o melhor horário para conversarmos?';

    public function create(int $w, int $user, string $caller): array
    {
        abort_unless($w === 1 && DB::table('users')->where('id', $user)->where('voice_workspace_id', $w)->exists(), 403);
        abort_unless(preg_match('/^\+[1-9][0-9]{7,14}$/D', $caller), 422);

        return DB::transaction(function () use ($w, $user, $caller) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $createdId = DB::table('voice_audit')->where('workspace_id', $w)->where('event', 'journey.preset.created')->orderBy('id')->value('subject_id');
            $existing = $createdId ? DB::table('voice_campaigns')->where('workspace_id', $w)->where('id', $createdId)->first() : DB::table('voice_campaigns')->where('workspace_id', $w)->where('name', self::NAME)->first();
            // Deployment re-runs must not overwrite subsequent user configuration.
            if ($existing) {
                return ['campaign_id' => $existing->id, 'created' => false];
            }
            $list = DB::table('voice_lists')->insertGetId(['workspace_id' => $w, 'name' => 'Zyrex | Público da jornada 5 tentativas', 'source' => 'Contatos a selecionar ou importar com autorização', 'created_at' => now(), 'updated_at' => now()]);
            $sender = DB::table('wa_senders')->where('workspace_id', $w)->where('number', $caller)->where('provider', 'twilio')->first();
            $senderId = $sender?->id ?? DB::table('wa_senders')->insertGetId(['workspace_id' => $w, 'number' => $caller, 'label' => 'Zyrex · WhatsApp a conectar', 'ownership' => 'twilio', 'provider' => 'twilio', 'created_at' => now(), 'updated_at' => now()]);
            $template = DB::table('wa_templates')->where('workspace_id', $w)->where('name', self::TEMPLATE)->where('language', 'pt_BR')->first();
            $template ??= app(WhatsAppTemplates::class)->create($w, ['name' => self::TEMPLATE, 'language' => 'pt_BR', 'category' => 'MARKETING', 'body' => str_replace('{nome}', '{{1}}', self::TEXT), 'variables' => ['1' => 'Ana Souza']]);
            $settings = ['mode' => 'progressive', 'crm_campaign_id' => null, 'segment_id' => null, 'business_number' => $caller, 'number_mode' => 'single', 'whatsapp_number' => $caller, 'whatsapp_sender_id' => $senderId,
                'timezone' => 'America/Sao_Paulo', 'days' => [1, 2, 3, 4, 5, 6, 7], 'start_time' => '09:00', 'end_time' => '18:00', 'max_attempts' => 5, 'retry_minutes' => 60, 'concurrency' => 1,
                'script' => 'Identifique-se como equipe Zyrex, confirme com quem está falando e registre o resultado. Ao atender, o contato sai da abordagem e não recebe o WhatsApp de não atendimento.',
                'whatsapp_enabled' => true, 'whatsapp_after' => 5, 'whatsapp_delay' => 0, 'whatsapp_template_id' => null, 'whatsapp_delivery' => 'automatic', 'whatsapp_text' => self::TEXT, 'whatsapp_real_template_id' => $template->id, 'whatsapp_variables' => ['1' => '{nome}']];
            $campaign = DB::table('voice_campaigns')->insertGetId(['workspace_id' => $w, 'name' => self::NAME, 'status' => 'paused', 'settings' => json_encode($settings), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('voice_campaign_policies')->insert(['campaign_id' => $campaign, 'list_id' => $list, 'daily_per_contact' => 5, 'global_daily' => 5, 'global_total' => 20, 'technical_limit' => 3, 'retry_minutes' => '{}', 'origin_mode' => 'configured', 'created_at' => now(), 'updated_at' => now()]);
            $queue = DB::table('voice_live_queues')->insertGetId(['workspace_id' => $w, 'campaign_id' => $campaign, 'name' => 'Zyrex | Fila da jornada 5 tentativas', 'status' => 'paused', 'mode' => 'progressive', 'strategy' => 'fifo', 'wrapup_seconds' => 30, 'agent_ids' => json_encode([$user]), 'created_at' => now(), 'updated_at' => now()]);
            app(VoiceLab::class)->audit($w, $user, 'journey.preset.created', $campaign, ['list_id' => $list, 'queue_id' => $queue, 'template_id' => $template->id, 'contacts_added' => 0]);

            return ['campaign_id' => $campaign, 'list_id' => $list, 'queue_id' => $queue, 'template_id' => $template->id, 'sender_id' => $senderId, 'created' => true];
        });
    }
}
