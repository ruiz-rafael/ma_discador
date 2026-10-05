<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoiceLiveQueue
{
    public const ACTIVE = ['reserved', 'calling', 'tabulation'];

    public function settle(): void
    {
        foreach (DB::table('voice_live_reservations')->whereIn('status', self::ACTIVE)->get() as $r) {
            $status = null;
            if (! $r->call_id) {
                if (now()->gte($r->expires_at)) {
                    $status = 'expired';
                }
            } else {
                $c = DB::table('voice_outbound_calls')->find($r->call_id);
                if ($c->capacity_released_at && $c->status !== 'unknown') {
                    $status = ($c->answered_at || $c->status === 'completed') && ! $c->disposition_revision ? 'tabulation' : 'completed';
                }
            }
            if (! $status || $status === $r->status) {
                continue;
            }
            DB::table('voice_live_reservations')->where('id', $r->id)->update(['status' => $status, 'finished_at' => in_array($status, ['completed', 'expired']) ? now() : null, 'updated_at' => now()]);
            if ($status === 'completed') {
                $q = DB::table('voice_live_queues')->find($r->queue_id);
                DB::table('voice_agent_presence')->where('workspace_id', $r->workspace_id)->where('user_id', $r->user_id)->update(['available_after' => now()->addSeconds($q->wrapup_seconds), 'updated_at' => now()]);
            }
        }
    }

    public function configure(int $w, int $u, array $d, ?int $id = null): object
    {
        return DB::transaction(function () use ($w, $u, $d, $id) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $this->settle();
            DB::table('voice_campaigns')->where('workspace_id', $w)->where('id', $d['campaign_id'])->firstOrFail();
            abort_unless(DB::table('users')->where('voice_workspace_id', $w)->whereIn('id', $d['agent_ids'])->count() === count($d['agent_ids']), 422, 'Selecione atendentes deste workspace.');
            $values = ['name' => $d['name'], 'mode' => $d['mode'], 'strategy' => $d['strategy'], 'wrapup_seconds' => $d['wrapup_seconds'], 'agent_ids' => json_encode($d['agent_ids']), 'updated_at' => now()];
            if ($id) {
                $q = DB::table('voice_live_queues')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
                abort_unless($q->status === 'paused' && $q->revision === ($d['revision'] ?? -1) && $q->campaign_id === $d['campaign_id'], 409, 'Pause a fila e atualize seus dados.');
                abort_if(DB::table('voice_live_reservations')->where('queue_id', $id)->whereIn('status', self::ACTIVE)->exists(), 409, 'Conclua as reservas atuais.');
                DB::table('voice_live_queues')->where('id', $id)->update($values + ['revision' => $q->revision + 1]);
            } else {
                abort_if(DB::table('voice_live_queues')->where('campaign_id', $d['campaign_id'])->exists(), 409, 'Campanha já possui fila real.');
                $id = DB::table('voice_live_queues')->insertGetId($values + ['workspace_id' => $w, 'campaign_id' => $d['campaign_id'], 'created_at' => now()]);
            }
            app(VoiceLab::class)->audit($w, $u, 'live_queue.configured', $id);

            return DB::table('voice_live_queues')->find($id);
        });
    }

    public function claim(int $w, int $u, int $id, string $key): array
    {
        return DB::transaction(function () use ($w, $u, $id, $key) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            app(VoiceCalling::class)->expire();
            abort_unless(DB::table('users')->where('id', $u)->where('voice_workspace_id', $w)->where('voice_enabled', true)->exists(), 403, 'Atendente desativado.');
            $this->settle();
            $q = DB::table('voice_live_queues')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
            abort_unless(in_array($u, json_decode($q->agent_ids, true), true), 403, 'Você não está vinculado a esta fila.');
            $old = DB::table('voice_live_reservations')->where('workspace_id', $w)->where('idempotency_key', $key)->first();
            if ($old) {
                abort_unless($old->user_id === $u && $old->queue_id === $id, 409);

                return ['reservation' => $old];
            }
            if (DB::table('voice_audio_sessions')->whereIn('status',['pending','connecting','active'])->where('expires_at','>',now())->exists()) return ['reservation'=>null,'retry_after'=>10,'message'=>'Aguardando o diagnóstico interno de áudio terminar.','reasons'=>[]];
            if (app(VoiceAgentCapacity::class)->inboundBusy($w,$u)) return ['reservation'=>null,'retry_after'=>10,'message'=>'Conclua o atendimento receptivo e a tabulação.','reasons'=>[]];
            if ($q->status !== 'running') return ['reservation' => null, 'retry_after' => 15, 'message' => 'Aguardando a supervisão habilitar a fila.', 'reasons' => []];
            $p = DB::table('voice_agent_presence')->where('workspace_id', $w)->where('user_id', $u)->first();
            abort_unless($p && $p->status === 'available' && $p->last_seen_at && CarbonImmutable::parse($p->last_seen_at)->gt(now()->subSeconds(90)), 409, 'Fique disponível para atendimento.');
            if ($p->available_after && CarbonImmutable::parse($p->available_after)->isFuture()) return ['reservation' => null, 'retry_after' => max(1, (int) now()->diffInSeconds(CarbonImmutable::parse($p->available_after))), 'message' => 'Aguarde o pós-atendimento.', 'reasons' => []];
            abort_if(DB::table('voice_live_reservations')->where('workspace_id', $w)->where('user_id', $u)->whereIn('status', self::ACTIVE)->exists() || DB::table('voice_queue_assignments')->where('workspace_id', $w)->where('user_id', $u)->where('status', 'active')->exists() || DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('user_id', $u)->whereNull('capacity_released_at')->exists(), 409, 'Conclua sua reserva, chamada ou tabulação atual.');
            if (DB::table('voice_inbound_calls')->whereNull('capacity_released_at')->exists() || DB::table('voice_outbound_calls')->whereNull('capacity_released_at')->exists()) return ['reservation' => null, 'retry_after' => 10, 'message' => 'Aguardando capacidade de telefonia.', 'reasons' => []];
            app(VoiceAudience::class)->sync($w, $q->campaign_id);
            $campaign = DB::table('voice_campaigns')->find($q->campaign_id);
            $contacts = DB::table('voice_contacts')->where('workspace_id', $w)->whereIn('id', DB::table('voice_members')->where('campaign_id', $campaign->id)->select('contact_id'))->get();
            // One aggregate, stable rounds: untouched contacts before retries.
            $attempts = DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('campaign_id', $campaign->id)
                ->whereNotNull('started_at')->selectRaw('contact_id, count(*) as attempts, max(started_at) as last_attempt')->groupBy('contact_id')->get()->keyBy('contact_id');
            $contacts = $contacts->sort(function ($a, $b) use ($attempts) {
                $aa = $attempts->get($a->id); $bb = $attempts->get($b->id);
                return (($aa->attempts ?? 0) <=> ($bb->attempts ?? 0))
                    ?: strcmp($aa->last_attempt ?? '', $bb->last_attempt ?? '') ?: ($a->id <=> $b->id);
            });
            $reasons = [];
            foreach ($contacts as $c) {
                $reason = app(VoiceEligibility::class)->reason($w, $campaign, $c);
                if ($reason) {
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;

                    continue;
                }
                if (DB::table('voice_live_reservations')->where('workspace_id', $w)->where('contact_id', $c->id)->whereIn('status', self::ACTIVE)->exists() || DB::table('voice_queue_assignments as a')->join('voice_queue_items as i', 'i.id', '=', 'a.item_id')->where('a.workspace_id', $w)->where('i.contact_id', $c->id)->where('a.status', 'active')->exists()) {
                    continue;
                }
                $rid = (string) Str::uuid();
                DB::table('voice_live_reservations')->insert(['id' => $rid, 'workspace_id' => $w, 'queue_id' => $id, 'contact_id' => $c->id, 'user_id' => $u, 'idempotency_key' => $key, 'expires_at' => now()->addMinutes(3), 'created_at' => now(), 'updated_at' => now()]);
                app(VoiceLab::class)->audit($w, $u, 'live_queue.reserved', $rid);

                return ['reservation' => DB::table('voice_live_reservations')->find($rid)];
            }

return ['reservation' => null, 'retry_after' => 15, 'reasons' => $reasons, 'message' => 'Aguardando contatos elegíveis. A fila será consultada novamente automaticamente.'];
        });
    }

    public function validateReservation(int $w, int $u, array $d): ?object
    {
        $this->settle();
        $id = $d['queue_reservation_id'] ?? null;
        if (! $id) {
            abort_if(DB::table('voice_live_reservations')->where('workspace_id', $w)->whereIn('status', self::ACTIVE)->where(fn ($q) => $q->where('user_id', $u)->orWhere('contact_id', $d['contact_id']))->exists(), 409, 'Há uma reserva de fila real para o contato ou atendente.');

            return null;
        }
        $r = DB::table('voice_live_reservations')->where('workspace_id', $w)->where('id', $id)->where('user_id', $u)->firstOrFail();
        $q = DB::table('voice_live_queues')->find($r->queue_id);
        abort_unless($r->status === 'reserved' && ! $r->call_id && now()->lt($r->expires_at) && $r->contact_id === $d['contact_id'] && $q->campaign_id === ($d['campaign_id'] ?? null) && $q->status === 'running', 409, 'Reserva expirada, utilizada ou diferente da chamada.');

        return $r;
    }

    public function assertDial(object $call): void
    {
        $q = DB::table('voice_live_queues')->find($call->queue_id);
        $r = DB::table('voice_live_reservations')->where('call_id', $call->id)->first();
        $p = DB::table('voice_agent_presence')->where('user_id', $call->user_id)->first();
        abort_unless(DB::table('users')->where('id',$call->user_id)->where('voice_enabled',true)->exists() && $q && $q->status === 'running' && in_array($call->user_id, json_decode($q->agent_ids, true), true) && $r && $r->status === 'calling' && $p && $p->status === 'available' && CarbonImmutable::parse($p->last_seen_at)->gt(now()->subSeconds(90)), 422, 'Fila ou atendente indisponível antes da discagem.');
    }

    public function cancel(int $w, int $u, string $id): void
    {
        DB::transaction(function () use ($w, $u, $id) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $r = DB::table('voice_live_reservations')->where('workspace_id', $w)->where('user_id', $u)->where('id', $id)->firstOrFail();
            abort_unless(! $r->call_id && $r->status === 'reserved', 409, 'Há chamada vinculada. Encerre e concilie pela telefonia.');
            DB::table('voice_live_reservations')->where('id', $id)->update(['status' => 'cancelled', 'finished_at' => now(), 'updated_at' => now()]);
        });
    }

    public function snapshot(int $w, int $u): array
    {
        return DB::transaction(function () use ($w, $u) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            app(VoiceCalling::class)->expire();
            $this->settle();
            $manage = in_array(DB::table('users')->where('id', $u)->value('voice_role'), ['admin', 'supervisor'], true);
            $queues = DB::table('voice_live_queues')->where('workspace_id', $w)->get();
            if (! $manage) $queues = $queues->filter(fn ($q) => in_array($u, json_decode($q->agent_ids, true), true))->values();
            foreach ($queues as $q) {
                $q->agent_ids = json_decode($q->agent_ids, true);
                $q->counts = DB::table('voice_live_reservations')->where('queue_id', $q->id)->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status');
            }
            $current = DB::table('voice_live_reservations')->where('workspace_id', $w)->where('user_id', $u)->whereIn('status', self::ACTIVE)->first();
            if ($current) {
                $current->contact = DB::table('voice_contacts')->find($current->contact_id);
                $current->queue = DB::table('voice_live_queues')->find($current->queue_id);
                $current->contact = app(VoiceAudience::class)->personalize($w, $current->queue->campaign_id, $current->contact);
                $current->call = $current->call_id ? DB::table('voice_outbound_calls')->where('id',$current->call_id)->first(['id', 'status', 'disposition_revision', 'disposition_code', 'capacity_released_at']) : null;
            }

            $team = $manage ? DB::table('users as u')->leftJoin('voice_agent_presence as p', function ($j) use ($w) { $j->on('p.user_id', '=', 'u.id')->where('p.workspace_id', $w); })->where('u.voice_workspace_id', $w)->get(['u.id','u.name','p.status','p.last_seen_at','p.available_after'])->map(function ($a) {
                $a->online = $a->last_seen_at && CarbonImmutable::parse($a->last_seen_at)->gt(now()->subSeconds(90));
                if (! $a->online) $a->status = 'offline';
                return $a;
            }) : [];
            return ['queues' => $queues, 'team' => $team, 'current' => $current, 'presence' => DB::table('voice_agent_presence')->where('user_id',$u)->where('workspace_id',$w)->first(), 'user_id' => $u];
        });
    }
}
