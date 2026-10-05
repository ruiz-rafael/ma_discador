<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoiceFollowups
{
    public static function ruleChanged(array $old, array $next): bool
    {
        // Hours, script, retry spacing and labels do not erase confirmed attempts.
        foreach (['whatsapp_enabled','whatsapp_delivery','whatsapp_after','whatsapp_delay','whatsapp_text','whatsapp_qr_template_id','whatsapp_qr_buttons_confirmed','whatsapp_variables','whatsapp_real_template_id','whatsapp_template_id','whatsapp_sender_id','whatsapp_number','number_mode','business_number'] as $field) {
            if (($old[$field] ?? null) != ($next[$field] ?? null)) return true;
        }
        return false;
    }

    // Called inside the same voice_runtime transaction as the trusted terminal event.
    public function observe(string $id): void
    {
        $call = DB::table('voice_outbound_calls')->find($id);
        if (!$call || !$call->started_at) { return; }
        if ($call->answered_at || $call->status === 'completed') {
            DB::table('voice_followups')->where('workspace_id', $call->workspace_id)->where('contact_id', $call->contact_id)->whereIn('status', ['pending', 'blocked'])->update(['status' => 'cancelled', 'reason' => 'Contato atendido por voz.', 'updated_at' => now()]);
            return;
        }
        if (!$call->campaign_id || !$call->ended_at) { return; }
        $campaign = DB::table('voice_campaigns')->where('workspace_id', $call->workspace_id)->where('id', $call->campaign_id)->first();
        if (!$campaign) { return; }
        $s = json_decode($campaign->settings, true);
        if ($call->status !== 'no_answer' || empty($s['whatsapp_enabled']) || ($s['whatsapp_delivery'] ?? 'simulation') !== 'automatic' || $call->campaign_revision !== $campaign->followup_revision) { return; }
        $calls = DB::table('voice_outbound_calls')->where('workspace_id', $call->workspace_id)->where('campaign_id', $campaign->id)->where('contact_id', $call->contact_id)->where('campaign_revision', $campaign->followup_revision)->where('destination', $call->destination)->whereNotNull('started_at');
        if ((clone $calls)->where(fn ($q) => $q->whereNotNull('answered_at')->orWhere('status', 'completed'))->exists()) { return; }
        $missed = (clone $calls)->where('status', 'no_answer')->whereNull('answered_at')->whereNotNull('ended_at')->count();
        if ($missed < (int) $s['whatsapp_after']) { return; }
        DB::table('voice_followups')->insertOrIgnore(['id' => (string) Str::uuid(), 'workspace_id' => $call->workspace_id, 'campaign_id' => $campaign->id, 'contact_id' => $call->contact_id, 'user_id' => $call->user_id, 'call_id' => $call->id, 'campaign_revision' => $campaign->followup_revision, 'settings' => json_encode($s), 'destination' => $call->destination, 'status' => 'pending', 'due_at' => now()->addMinutes($s['whatsapp_delay']), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function assertEligible(string $id): object
    {
        $f = DB::table('voice_followups')->find($id);
        abort_unless($f && in_array($f->status, ['pending', 'blocked', 'dispatching']), 422, 'Passo de WhatsApp encerrado.');
        $campaign = DB::table('voice_campaigns')->where('workspace_id', $f->workspace_id)->where('id', $f->campaign_id)->firstOrFail();
        $s = json_decode($campaign->settings, true);
        abort_unless($campaign->followup_revision === $f->campaign_revision && ($s['whatsapp_delivery'] ?? '') === 'automatic' && $s['whatsapp_enabled'], 422, 'Configuração alterada ou envio automático desativado.');
        abort_unless($campaign->status === 'testing', 422, 'Campanha pausada ou encerrada.');
        abort_unless(app(VoiceLab::class)->window($s), 422, 'Fora do horário da campanha.');
        $c = DB::table('voice_contacts')->where('workspace_id', $f->workspace_id)->where('id', $f->contact_id)->firstOrFail();
        abort_unless($c->consent && $c->consent_evidence && !$c->suppressed_at && !$c->replied_at && $c->phone === $f->destination, 422, 'Contato indisponível, sem autorização ou com telefone alterado.');
        abort_unless(DB::table('voice_members')->where('campaign_id', $f->campaign_id)->where('contact_id', $c->id)->exists(), 422, 'Contato removido da campanha.');
        abort_if(DB::table('voice_outbound_calls')->where('workspace_id', $f->workspace_id)->where('contact_id', $c->id)->where(function ($q) use ($f) {
            $q->whereNull('capacity_released_at')->orWhere(fn ($q) => $q->where('created_at', '>=', $f->created_at)->where(fn ($q) => $q->whereNotNull('answered_at')->orWhere('status', 'completed')));
        })->exists(), 422, 'Contato em chamada, com resultado incerto ou já atendido.');
        $policy = DB::table('voice_campaign_policies')->where('campaign_id',$f->campaign_id)->first();
        $sourceReason = app(VoiceAudience::class)->reason($f->workspace_id, $policy, $c);
        abort_if($sourceReason, 422, $sourceReason);
        abort_if($policy?->expires_at && now()->gte($policy->expires_at),422,'Prazo da campanha encerrado.');
        if($policy?->list_id)app(Segments::class)->refresh($f->workspace_id,'voice',$policy->list_id);
        abort_if($policy?->list_id && !DB::table('voice_list_members')->where('list_id',$policy->list_id)->where('contact_id',$c->id)->where('status','active')->exists(),422,'Contato retirado da lista.');
        $s = app(WhatsAppNumbers::class)->normalize($f->workspace_id, $s);
        $sender = app(WhatsAppMessages::class)->sender($f->workspace_id, (int) ($s['whatsapp_sender_id'] ?? 0));
        $call = DB::table('voice_outbound_calls')->find($f->call_id);
        abort_unless($s['number_mode'] !== 'single' || $sender->number === $call->caller_id, 422, 'O número conectado ao WhatsApp difere da origem da chamada.');
        return $f;
    }

    public function dispatch(string $id): void
    {
        $f = DB::transaction(function () use ($id) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $f = DB::table('voice_followups')->find($id);
            if (!$f || $f->status !== 'pending' || now()->lt($f->due_at)) { return null; }
            $campaign = DB::table('voice_campaigns')->find($f->campaign_id);
            $s = json_decode($campaign->settings, true);
            if ($campaign->followup_revision === $f->campaign_revision && ($campaign->status === 'paused' || !app(VoiceLab::class)->window($s))) { return null; }
            try { $this->assertEligible($id); }
            catch (\Throwable $e) {
                DB::table('voice_followups')->where('id', $id)->update(['status' => 'cancelled', 'reason' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
                return null;
            }
            DB::table('voice_followups')->where('id', $id)->update(['status' => 'dispatching', 'reason' => null, 'updated_at' => now()]);
            return $f;
        });
        if (!$f) { return; }
        try {
            $s = json_decode($f->settings, true);
            $contact = DB::table('voice_contacts')->find($f->contact_id);
            $campaign = DB::table('voice_campaigns')->find($f->campaign_id);
            $personalization = app(VoicePersonalization::class);
            $sender = app(WhatsAppMessages::class)->sender($f->workspace_id, $s['whatsapp_sender_id']);
            $d = ['sender_id' => $sender->id, 'contact_id' => $contact->id, 'campaign_id' => $f->campaign_id, 'idempotency_key' => $f->id, 'consent_evidence' => mb_substr($contact->consent_evidence, 0, 1000)];
            if ($sender->provider === 'qr') {
                if (!empty($s['whatsapp_qr_template_id'])) {
                    $d['qr_template_id'] = $s['whatsapp_qr_template_id'];
                    $d['experimental_confirmed'] = $s['whatsapp_qr_buttons_confirmed'] ?? false;
                } else {
                    $d['body'] = $personalization->render($s['whatsapp_text'], $contact, $campaign);
                }
                $m = app(WhatsAppQr::class)->send($f->workspace_id, $f->user_id, $d, $f->id);
            } else {
                $d['template_id'] = $s['whatsapp_real_template_id'];
                $d['variables'] = array_map(fn ($v) => $personalization->render($v, $contact, $campaign), $s['whatsapp_variables'] ?? []);
                $m = app(WhatsAppMessages::class)->send($f->workspace_id, $f->user_id, $d, $f->id);
            }
            DB::table('voice_followups')->where('id', $id)->update(['message_id' => $m->id, 'status' => $m->status, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            $m = DB::table('wa_messages')->where('workspace_id', $f->workspace_id)->where('idempotency_key', $id)->first();
            // A reserved or uncertain message is never automatically submitted a second time.
            DB::table('voice_followups')->where('id', $id)->update(['message_id' => $m?->id, 'status' => $m ? 'unknown' : 'blocked', 'reason' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
        }
    }

    public function retry(int $w, string $id): void
    {
        DB::transaction(function () use ($w, $id) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $f = DB::table('voice_followups')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
            abort_unless($f->status === 'blocked' && !$f->message_id && !DB::table('wa_messages')->where('workspace_id', $w)->where('idempotency_key', $id)->exists(), 409, 'Há tentativa de envio ou este passo já foi encerrado.');
            $this->assertEligible($id);
            DB::table('voice_followups')->where('id', $id)->update(['status' => 'pending', 'reason' => null, 'updated_at' => now()]);
        });
    }

    public function run(): void
    {
        app(WhatsAppQr::class)->poll();
        foreach (DB::table('voice_followups')->where('status', 'dispatching')->where('updated_at', '<', now()->subMinutes(2))->get() as $f) {
            $m = DB::table('wa_messages')->where('workspace_id', $f->workspace_id)->where('idempotency_key', $f->id)->first();
            DB::table('voice_followups')->where('id', $f->id)->where('status', 'dispatching')->update(['message_id' => $m?->id, 'status' => $m ? 'unknown' : 'blocked', 'reason' => 'Processamento interrompido; confira antes de reavaliar.', 'updated_at' => now()]);
        }
        foreach (DB::table('voice_followups')->whereNotNull('message_id')->get() as $f) {
            $m = DB::table('wa_messages')->find($f->message_id);
            if ($m && $m->status !== 'sending') { DB::table('voice_followups')->where('id', $f->id)->update(['status' => $m->status, 'updated_at' => now()]); }
        }
        foreach (DB::table('voice_followups')->where('status', 'pending')->where('due_at', '<=', now())->orderBy('due_at')->limit(20)->pluck('id') as $id) { $this->dispatch($id); }
    }
}
