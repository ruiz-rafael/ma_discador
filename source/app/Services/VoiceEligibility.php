<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class VoiceEligibility
{
    public function globalReason(int $w, int $contact, ?string $exclude = null): ?string
    {
        $limits = DB::table('voice_campaign_policies as p')->join('voice_campaigns as c', 'c.id', '=', 'p.campaign_id')->where('c.workspace_id', $w)->where(fn ($q) => $q->whereIn('c.id', DB::table('voice_members')->where('contact_id', $contact)->select('campaign_id'))->orWhereIn('c.id', DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('contact_id', $contact)->whereNotNull('campaign_id')->select('campaign_id')))->selectRaw('min(p.global_daily) as daily, min(p.global_total) as total')->first();
        if (! $limits->total) {
            return null;
        }
        $q = DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('contact_id', $contact)->whereNotNull('started_at');
        if ($exclude) {
            $q->where('id', '!=', $exclude);
        }
        if ((clone $q)->count() >= $limits->total) {
            return 'Limite acumulado do contato entre campanhas atingido.';
        }
        if ((clone $q)->where('started_at', '>=', CarbonImmutable::now('UTC')->startOfDay())->count() >= $limits->daily) {
            return 'Limite diário do contato entre campanhas atingido (UTC).';
        }

        return null;
    }

    public function reason(int $w, object $campaign, object $contact, ?string $exclude = null): ?string
    {
        $s = json_decode($campaign->settings, true);
        $run=app(CadenceReentry::class)->current($campaign->id,$contact->id);
        $p = DB::table('voice_campaign_policies')->where('campaign_id', $campaign->id)->first();
        if ($reason = app(VoiceAudience::class)->reason($w, $p, $contact)) return $reason;
        if (! $contact->consent || ! $contact->consent_evidence || $contact->suppressed_at || app(CadenceReentry::class)->replyBlocks($contact,$run?->id)) {
            return 'Contato sem autorização ou com abordagem encerrada.';
        }
        if ($campaign->status !== 'testing') {
            return 'Campanha pausada ou não iniciada.';
        }
        if (! app(VoiceLab::class)->window($s)) {
            return 'Fora do horário da campanha.';
        }
        if (! DB::table('voice_members')->where('campaign_id', $campaign->id)->where('contact_id', $contact->id)->exists()) {
            return 'Contato fora da campanha.';
        }
        if ($p?->expires_at && now()->gte($p->expires_at)) {
            return 'Prazo da campanha encerrado.';
        }
        if($p?->list_id)app(Segments::class)->refresh($w,'voice',$p->list_id);
        if ($p?->list_id && ! DB::table('voice_list_members')->where('list_id', $p->list_id)->where('contact_id', $contact->id)->where('status', 'active')->exists()) {
            return 'Contato retirado da lista.';
        }
        if(isset($s['reentry'])&&(!$run||app(CadenceReentry::class)->current($campaign->id,$contact->id)?->id!==$run->id))return 'Aguardando uma nova participação elegível na cadência.';
        if (DB::table('voice_followups')->where('campaign_id', $campaign->id)->where('contact_id', $contact->id)->where('run_id',$run?->id)->exists()) {
            return 'Etapa de voz encerrada; consulte o WhatsApp.';
        }
        $all = DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('contact_id', $contact->id);
        if ($exclude) {
            $all->where('id', '!=', $exclude);
        }
        if ((clone $all)->whereNull('capacity_released_at')->exists()) {
            return 'Contato em chamada ou aguardando confirmação.';
        }
        if ($reason = $this->globalReason($w, $contact->id, $exclude)) {
            return $reason;
        }
        $dailyCalls=(clone $all)->where('campaign_id',$campaign->id);
        $calls = (clone $dailyCalls)->where('run_id',$run?->id);
        if ((clone $calls)->where(fn ($q) => $q->whereNotNull('answered_at')->orWhere('status', 'completed'))->exists()) {
            return 'Contato atendido; jornada de prospecção encerrada.';
        }
        $started = (clone $calls)->whereNotNull('started_at');
        if ((clone $started)->count() >= $s['max_attempts']) {
            return 'Máximo de tentativas da campanha atingido.';
        }
        $day = CarbonImmutable::now($s['timezone'])->startOfDay()->utc();
        if ($p) {
            if ((clone $dailyCalls)->whereNotNull('started_at')->where('started_at', '>=', $day)->count() >= $p->daily_per_contact) {
                return 'Limite diário do contato na campanha atingido.';
            }
            if ((clone $dailyCalls)->where('created_at', '>=', $day)->whereIn('status', ['failed', 'unknown'])->count() >= $p->technical_limit) {
                return 'Limite diário de falhas técnicas atingido; conferir a rota.';
            }
        }
        $last = (clone $started)->orderByDesc('started_at')->first();
        if ($last) {
            $retry = $p ? json_decode($p->retry_minutes, true) : [];
            $minutes = $retry[$last->status] ?? $s['retry_minutes'];
            if (CarbonImmutable::parse($last->started_at)->addMinutes($minutes)->isFuture()) {
                return 'Aguarde o intervalo entre tentativas.';
            }
        }

        return null;
    }

    public function assertDial(object $call): void
    {
        abort_unless(DB::table('users')->where('id',$call->user_id)->where('voice_enabled',true)->exists(),422,'Atendente desativado.');
        abort_if(app(OperationPolicy::class)->get($call->workspace_id)['paused'],422,'Operação pausada pela supervisão.');
        $global = $this->globalReason($call->workspace_id, $call->contact_id, $call->id);
        abort_if($global, 422, $global);
        if($call->queue_id)app(VoiceLiveQueue::class)->assertDial($call);
        if (! $call->campaign_id) {
            return;
        }
        $campaign = DB::table('voice_campaigns')->where('workspace_id', $call->workspace_id)->where('id', $call->campaign_id)->firstOrFail();
        $s = json_decode($campaign->settings, true);
        // Legacy manual tests remain compatible until explicitly configured as an operational campaign.
        if (($s['whatsapp_delivery'] ?? '') !== 'automatic' && ! $call->queue_id && ! DB::table('voice_campaign_policies')->where('campaign_id', $campaign->id)->exists()) {
            return;
        }
        if($call->run_id)abort_unless(app(CadenceReentry::class)->current($campaign->id,$call->contact_id)?->id===$call->run_id,422,'Participação encerrada ou substituída.');
        abort_unless($campaign->followup_revision === $call->campaign_revision, 422, 'Configuração alterada depois da reserva.');
        $reason = $this->reason($call->workspace_id, $campaign, DB::table('voice_contacts')->find($call->contact_id), $call->id);
        abort_if($reason,422,$reason);
    }
}
