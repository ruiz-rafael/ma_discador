<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoiceCalling
{
    public function expire(): void
    {
        DB::table('voice_outbound_calls')->where('status', 'pending')->where('grant_expires_at', '<=', now())->update(['status' => 'cancelled', 'capacity_released_at' => now(), 'updated_at' => now()]);
        // A missing PBX finish is not proof of hangup. Keep capacity reserved until reconciliation.
        DB::table('voice_outbound_calls')->whereIn('status', ['dialing', 'answered'])->where('deadline_at', '<=', now())->update(['status' => 'unknown', 'updated_at' => now()]);
    }

    public function contact(int $w, int $id): object
    {
        $c = DB::table('voice_contacts')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
        abort_unless($c->consent && ! $c->suppressed_at && ! $c->replied_at, 422, 'Contato sem autorização ou com abordagem interrompida.');

        return $c;
    }

    public function reserve(int $w, int $user, array $d): array
    {
        $manage = in_array(DB::table('users')->where('id', $user)->value('voice_role'), ['admin', 'supervisor'], true);
        abort_if(! $manage && empty($d['queue_reservation_id']), 403, 'O agente deve usar o contato reservado em sua fila.');
        $config = app(VoiceCallingConfig::class);
        $method = $d['method'] ?? 'sip_trunk';
        abort_unless(in_array($method, ['sip_trunk', 'programmable_voice'], true), 422);
        abort_unless($config->status($method)['ready'], 503, 'Configure e habilite o método escolhido e os limites antes de ligar.');

        return DB::transaction(function () use ($w, $user, $d, $config, $method) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            abort_unless(DB::table('users')->where('id', $user)->where('voice_workspace_id', $w)->where('voice_enabled', true)->exists(), 403, 'Atendente desativado.');
            $this->expire();
            $p = $config->read();
            $t = $config->connection($method);
            abort_unless($config->status($method)['ready'], 503);
            $hash = hash('sha256', json_encode([$method, $user, $d['contact_id'], $d['campaign_id'] ?? null, $d['consent_evidence'], $d['queue_reservation_id'] ?? null], JSON_THROW_ON_ERROR));
            $old = DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) {
                abort_unless($old->request_hash === $hash && $old->user_id === $user, 409, 'Identificação já utilizada para outra ligação.');
                abort_unless($old->configuration_hash === $config->fingerprint($method), 409, 'A configuração mudou; cancele a reserva anterior.');
                abort_unless($old->status === 'pending', 409, 'Tentativa já utilizada ou expirada. Consulte o histórico antes de iniciar outra.');

                return $this->grant($old, $p);
            }
            $limitReason = app(OperationPolicy::class)->voiceReason($w); abort_if($limitReason,429,$limitReason);
            $contact = $this->contact($w, $d['contact_id']);
            $global = app(VoiceEligibility::class)->globalReason($w, $contact->id); abort_if($global, 422, $global);
            $reservation = app(VoiceLiveQueue::class)->validateReservation($w, $user, $d);
            app(VoiceQueue::class)->expire($w);
            abort_if(DB::table('voice_queue_assignments as a')->join('voice_queue_items as i', 'i.id', '=', 'a.item_id')->where('a.workspace_id', $w)->where('a.status', 'active')->where(fn ($q) => $q->where('a.user_id', $user)->orWhere('i.contact_id', $contact->id))->exists(), 409, 'Conclua a reserva simulada da fila antes de iniciar esta ligação real.');
            abort_unless(in_array($contact->phone, $p['allowed_recipients'], true), 422, 'Destino fora da lista privada de homologação.');
            if (! empty($d['campaign_id'])) {
                $campaign = DB::table('voice_campaigns')->where('workspace_id', $w)->where('id', $d['campaign_id'])->firstOrFail();
                $settings = json_decode($campaign->settings, true);
                if (($settings['whatsapp_delivery'] ?? '') === 'automatic' || $reservation || DB::table('voice_campaign_policies')->where('campaign_id', $campaign->id)->exists()) {
                    $reason = app(VoiceEligibility::class)->reason($w, $campaign, $contact); abort_if($reason, 422, $reason);
                }
                $t['caller_id'] = app(VoiceProviderCatalog::class)->choose($w, $campaign, $contact, $t);
                abort_unless(DB::table('voice_members')->where('campaign_id', $campaign->id)->where('contact_id', $contact->id)->exists(), 422, 'Contato fora da campanha.');
            }
            abort_if(app(VoiceAgentCapacity::class)->inboundBusy($w,$user) || DB::table('voice_inbound_calls')->whereNull('capacity_released_at')->exists() || DB::table('voice_outbound_calls')->whereNull('capacity_released_at')->exists(), 409, 'Há ligação reservada, em andamento ou sem confirmação final.');
            app(VoiceAudio::class)->expire();
            abort_if(DB::table('voice_audio_sessions')->whereIn('status', ['pending', 'connecting', 'active'])->exists(), 409, 'Finalize o teste interno de áudio antes de ligar.');
            abort_if(DB::table('voice_outbound_calls')->where('created_at', '>=', now()->startOfDay())->count() >= $p['daily_limit'], 429, 'Limite diário de tentativas atingido.');
            $policy = isset($campaign) ? DB::table('voice_campaign_policies')->where('campaign_id', $campaign->id)->first() : null;
            $profile = isset($campaign) ? app(VoiceAudience::class)->personalize($w, $campaign->id, $contact) : $contact;
            $snapshot = ['contact_name'=>$profile->name, 'campaign_name'=>$campaign->name??null, 'agent_name'=>DB::table('users')->where('id',$user)->value('name'), 'origin_mode'=>$policy->origin_mode??'configured'];
            $id = (string) Str::uuid();
            $token = hash_hmac('sha256', $id, $p['event_secret']);
            DB::table('voice_outbound_calls')->insert(['id' => $id, 'list_id'=>$policy->list_id??null, 'queue_id'=>$reservation->queue_id??null, 'context_snapshot'=>json_encode($snapshot), 'workspace_id' => $w, 'user_id' => $user, 'contact_id' => $contact->id, 'campaign_id' => $d['campaign_id'] ?? null, 'campaign_revision' => isset($campaign) ? $campaign->followup_revision : null, 'idempotency_key' => $d['idempotency_key'], 'request_hash' => $hash, 'grant_hash' => hash('sha256', $token), 'configuration_hash' => $config->fingerprint($method), 'method' => $method, 'provider_account' => $t['account_sid'], 'destination' => $contact->phone, 'caller_id' => $t['caller_id'], 'status' => 'pending', 'max_seconds' => $p['max_seconds'], 'ring_seconds' => $p['ring_seconds'], 'consent_evidence' => $d['consent_evidence'], 'grant_expires_at' => now()->addSeconds(45), 'deadline_at' => now()->addSeconds(45 + $p['max_seconds'] + $p['ring_seconds'] + 60), 'created_at' => now(), 'updated_at' => now()]);
            if ($reservation) DB::table('voice_live_reservations')->where('id',$reservation->id)->update(['call_id'=>$id,'status'=>'calling','updated_at'=>now()]);
            app(VoiceLab::class)->audit($w, $user, 'calling.reserved', $id);

            return $this->grant(DB::table('voice_outbound_calls')->find($id), $p);
        });
    }

    private function grant(object $row, array $p): array
    {
        if ($row->method === 'programmable_voice') {
            return app(TwilioVoiceConnection::class)->grant($row, $p);
        }
        return ['id' => $row->id, 'method' => 'sip_trunk', 'server' => 'wss://ma.zyrex.ia.br/voice/ws', 'aor' => 'sip:'.$p['sip_username'].'@ma.zyrex.ia.br', 'username' => $p['sip_username'], 'password' => $p['sip_password'], 'destination' => 'sip:'.hash_hmac('sha256', $row->id, $p['event_secret']).'@ma.zyrex.ia.br', 'max_seconds' => $row->max_seconds, 'ring_seconds' => $row->ring_seconds];
    }

    public function event(array $d): array
    {
        return DB::transaction(function () use ($d) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $this->expire();
            $m = DB::table('voice_outbound_calls')->where('grant_hash', hash('sha256', $d['token']))->first();
            if (! $m || $m->method !== 'sip_trunk') {
                return ['allowed' => false];
            }
            if ($d['event'] === 'start') {
                $c = app(VoiceCallingConfig::class);
                $p = $c->read();
                if ($m->status === 'dialing' && $m->channel_id === $d['channel_id']) {
                    return $this->dialGrant($m);
                }
                if ($m->status !== 'pending' || ! $c->installed() || $m->configuration_hash !== $c->fingerprint()) {
                    return ['allowed' => false];
                }
                try {
                    $contact = $this->contact($m->workspace_id, $m->contact_id);
                    app(VoiceEligibility::class)->assertDial($m);
                    app(VoiceProviderCatalog::class)->assertOrigin($m);
                } catch (\Throwable) {
                    return ['allowed' => false];
                }
                if ($contact->phone !== $m->destination || ! in_array($m->destination, $p['allowed_recipients'], true)) {
                    return ['allowed' => false];
                }
                // Pace actual trunk attempts, not only browser reservations.
                if (DB::table('voice_outbound_calls')->where('started_at', '>=', now()->subSecond())->exists()) {
                    return ['allowed' => false];
                }
                DB::table('voice_outbound_calls')->where('id', $m->id)->update(['status' => 'dialing', 'channel_id' => $d['channel_id'], 'started_at' => now(), 'updated_at' => now()]);
                $result = $this->dialGrant($m);
            } else {
                if ($m->channel_id !== $d['channel_id']) {
                    return ['allowed' => false];
                }
                if ($d['event'] === 'answered') {
                    if ($m->status === 'answered') {
                        return ['allowed' => true];
                    }if ($m->status !== 'dialing') {
                        return ['allowed' => false];
                    }
                    DB::table('voice_outbound_calls')->where('id', $m->id)->update(['status' => 'answered', 'answered_at' => now(), 'updated_at' => now()]);
                } else {
                    if ($m->capacity_released_at) {
                        return ['allowed' => true];
                    }
                    if (! in_array($m->status, ['dialing', 'answered', 'unknown'])) {
                        return ['allowed' => false];
                    }
                    $dial = $d['dial_status'] ?? '';
                    $status = $m->answered_at || $dial === 'ANSWER' ? 'completed' : match ($dial) {
                        'BUSY' => 'busy','NOANSWER' => 'no_answer','CANCEL' => 'cancelled','CHANUNAVAIL','CONGESTION','INVALIDARGS' => 'failed',default => 'unknown'
                    };
                    DB::table('voice_outbound_calls')->where('id', $m->id)->update(['status' => $status, 'dial_status' => $dial, 'cause' => $d['cause'] ?? null, 'bill_seconds' => $d['bill_seconds'] ?? 0, 'ended_at' => now(), 'capacity_released_at' => now(), 'updated_at' => now()]);
                }$result = ['allowed' => true];
            }
            app(VoiceFollowups::class)->observe($m->id);
            app(VoiceLiveQueue::class)->settle();
            app(VoiceLab::class)->audit($m->workspace_id, $m->user_id, 'calling.'.$d['event'], $m->id);

            return $result;
        });
    }

    private function dialGrant(object $m): array
    {
        return ['allowed' => true, 'number' => $m->destination, 'caller_id' => $m->caller_id, 'max_seconds' => $m->max_seconds, 'ring_seconds' => $m->ring_seconds];
    }

    public function cancel(int $w, int $user, string $id): array
    {
        return DB::transaction(function () use ($w, $user, $id) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $q = DB::table('voice_outbound_calls')->where('workspace_id',$w)->where('user_id',$user)->where('id',$id);
            $m = $q->firstOrFail();
            if ($m->status === 'pending') {
                $q->update(['status' => 'cancelled', 'capacity_released_at' => now(), 'updated_at' => now()]);
            }

app(VoiceLiveQueue::class)->settle();
return ['cancelled' => $m->status === 'pending'];
        });
    }
}
