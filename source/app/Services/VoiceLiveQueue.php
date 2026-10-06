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
            if ($r->status !== 'tabulation' && in_array($status,['tabulation','completed']) && ($c->answered_at || $c->status==='completed')) {
                app(AgentWrapup::class)->start(DB::table('voice_live_queues')->find($r->queue_id),$r->user_id,'outbound:'.$c->id,$c->ended_at??$c->capacity_released_at);
            }
        }
    }

    public function configure(int $w, int $u, array $d, ?int $id = null): object
    {
        return app(QueueRouting::class)->configure($w,$u,$d,$id);
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
            abort_if($q->direction==='inbound',422,'Esta fila é receptiva e não reserva contatos de campanhas.');
            if ($q->status !== 'running') return ['reservation' => null, 'retry_after' => 15, 'message' => 'Aguardando a supervisão habilitar a fila.', 'reasons' => []];
            $p = DB::table('voice_agent_presence')->where('workspace_id', $w)->where('user_id', $u)->first();
            abort_unless($p && $p->status === 'available' && $p->last_seen_at && CarbonImmutable::parse($p->last_seen_at)->gt(now()->subSeconds(90)), 409, 'Fique disponível para atendimento.');
            abort_unless(AgentAvailability::selected($p,$id),409,'Você está offline nesta fila.');
            abort_if(DB::table('voice_live_reservations')->where('workspace_id', $w)->where('user_id', $u)->whereIn('status', self::ACTIVE)->exists() || DB::table('voice_queue_assignments')->where('workspace_id', $w)->where('user_id', $u)->where('status', 'active')->exists() || DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('user_id', $u)->whereNull('capacity_released_at')->exists(), 409, 'Conclua sua reserva, chamada ou tabulação atual.');
            if ($p->available_after && CarbonImmutable::parse($p->available_after)->isFuture()) return ['reservation' => null, 'retry_after' => max(1, (int) now()->diffInSeconds(CarbonImmutable::parse($p->available_after))), 'message' => 'Aguarde o pós-atendimento.', 'reasons' => []];
            if (app(VoiceCapacity::class)->full()) return ['reservation' => null, 'retry_after' => 10, 'message' => 'Aguardando capacidade de telefonia.', 'reasons' => []];
            if(!app(QueueDistribution::class)->outboundTurn($q,$u))return ['reservation'=>null,'retry_after'=>5,'message'=>'Aguardando sua vez na distribuição da fila.','reasons'=>[]];
            if(!QueueOperationSettings::open($q))return ['reservation'=>null,'retry_after'=>30,'message'=>'Fora do horário de funcionamento da fila.','reasons'=>[]];
            $campaignIds=QueueRouting::campaigns($q);
            // Alternate eligible campaigns; preserve attempt rounds inside each campaign.
            $last=DB::table('voice_live_reservations')->where('queue_id',$id)->selectRaw('campaign_id,max(created_at) as last_at')->groupBy('campaign_id')->pluck('last_at','campaign_id');
            usort($campaignIds,fn($a,$b)=>strcmp($last[$a]??'',$last[$b]??'')?:($a<=>$b));
            $reasons=[];
            foreach($campaignIds as $campaignId){
            $list=DB::table('voice_campaign_policies')->where('campaign_id',$campaignId)->value('list_id');if($list)app(Segments::class)->refresh($w,'voice',$list);
            app(VoiceAudience::class)->sync($w, $campaignId);
            $campaign=DB::table('voice_campaigns')->where('workspace_id',$w)->find($campaignId);if(!$campaign)continue;
            app(CadenceReentry::class)->sync($campaign);
            $contacts = DB::table('voice_contacts')->where('workspace_id', $w)->whereIn('id', DB::table('voice_members')->where('campaign_id', $campaign->id)->select('contact_id'))->get();
            $attempts = DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('campaign_id', $campaign->id)
                ->where(function($q){$q->whereNull('run_id')->orWhereIn('run_id',DB::table('voice_cadence_runs')->whereIn('status',['active','waiting'])->select('id'));})->whereNotNull('started_at')->selectRaw('contact_id, count(*) as attempts, max(started_at) as last_attempt')->groupBy('contact_id')->get()->keyBy('contact_id');
            $contacts = $contacts->sort(function ($a, $b) use ($attempts) {
                $aa = $attempts->get($a->id); $bb = $attempts->get($b->id);
                return (($aa->attempts ?? 0) <=> ($bb->attempts ?? 0))
                    ?: strcmp($aa->last_attempt ?? '', $bb->last_attempt ?? '') ?: ($a->id <=> $b->id);
            });
            foreach ($contacts as $c) {
                $reason = QueueDestinations::reason($q,$c->phone) ?? app(VoiceEligibility::class)->reason($w, $campaign, $c);
                if ($reason) {
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;

                    continue;
                }
                if (DB::table('voice_live_reservations')->where('workspace_id', $w)->where('contact_id', $c->id)->whereIn('status', self::ACTIVE)->exists() || DB::table('voice_queue_assignments as a')->join('voice_queue_items as i', 'i.id', '=', 'a.item_id')->where('a.workspace_id', $w)->where('i.contact_id', $c->id)->where('a.status', 'active')->exists()) {
                    continue;
                }
                $rid = (string) Str::uuid();
                DB::table('voice_live_reservations')->insert(['id' => $rid, 'run_id'=>app(CadenceReentry::class)->current($campaign->id,$c->id)?->id, 'workspace_id' => $w, 'queue_id' => $id, 'campaign_id'=>$campaign->id, 'contact_id' => $c->id, 'user_id' => $u, 'idempotency_key' => $key, 'expires_at' => now()->addMinutes(3), 'created_at' => now(), 'updated_at' => now()]);
                app(QueueDistribution::class)->record($q,$u,'outbound');
                app(VoiceLab::class)->audit($w, $u, 'live_queue.reserved', $rid);

                return ['reservation' => DB::table('voice_live_reservations')->find($rid)];
            }

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
        abort_unless($r->status === 'reserved' && ! $r->call_id && now()->lt($r->expires_at) && $r->contact_id === $d['contact_id'] && ($r->kind==='manual'?($q->manual_enabled&&empty($d['campaign_id'])):(($r->campaign_id??$q->campaign_id) === ($d['campaign_id'] ?? null) && in_array($d['campaign_id'],QueueRouting::campaigns($q),true))) && in_array($u,json_decode($q->agent_ids,true),true) && AgentAvailability::available($w,$u,$q->id) && $q->direction!=='inbound' && ($d['method']??'sip_trunk')===$q->calling_method && ($r->kind==='manual'||$q->status === 'running'), 409, 'Reserva expirada, utilizada ou diferente da chamada.');

        if($r->run_id)abort_unless(app(CadenceReentry::class)->current($r->campaign_id,$r->contact_id)?->id===$r->run_id,409,'A participação desta reserva foi encerrada.');
        QueueOperationSettings::check($q);QueueDestinations::check($q,DB::table('voice_contacts')->where('id',$r->contact_id)->value('phone'));
        return $r;
    }

    public function assertDial(object $call): void
    {
        $q = DB::table('voice_live_queues')->find($call->queue_id);
        $r = DB::table('voice_live_reservations')->where('call_id', $call->id)->first();
        $p = DB::table('voice_agent_presence')->where('user_id', $call->user_id)->first();
        abort_unless(DB::table('users')->where('id',$call->user_id)->where('voice_enabled',true)->exists() && $q && $q->direction!=='inbound' && $call->method===$q->calling_method && ($call->manual?($q->manual_enabled&&!$call->campaign_id):in_array($call->campaign_id,QueueRouting::campaigns($q),true)) && AgentAvailability::selected($p,$q->id) && ($call->manual||$q->status === 'running') && in_array($call->user_id, json_decode($q->agent_ids, true), true) && $r && $r->status === 'calling' && $p && $p->status === 'available' && CarbonImmutable::parse($p->last_seen_at)->gt(now()->subSeconds(90)), 422, 'Fila ou atendente indisponível antes da discagem.');
        QueueOperationSettings::check($q);QueueDestinations::check($q,DB::table('voice_contacts')->where('id',$call->contact_id)->value('phone'));
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
                $q->campaign_ids=QueueRouting::campaigns($q);
                $q->campaigns=DB::table('voice_campaigns')->where('workspace_id',$w)->whereIn('id',$q->campaign_ids)->get(['id','name','status']);
                $q->incoming_numbers=DB::table('voice_inbound_routes')->where('workspace_id',$w)->where('queue_id',$q->id)->get(['id','number','enabled']);
                $q->whatsapp_numbers=DB::table('wa_inbox_routes as r')->join('wa_senders as s','s.id','=','r.sender_id')->where('r.workspace_id',$w)->where('r.queue_id',$q->id)->get(['s.id','s.label','s.number','s.provider']);
                $q->agent_ids = json_decode($q->agent_ids, true);
                $q->counts = DB::table('voice_live_reservations')->where('queue_id', $q->id)->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status');
            }
            $current = DB::table('voice_live_reservations')->where('workspace_id', $w)->where('user_id', $u)->whereIn('status', self::ACTIVE)->first();
            if ($current) {
                $current->contact = DB::table('voice_contacts')->find($current->contact_id);
                $current->queue = DB::table('voice_live_queues')->find($current->queue_id);
                if($current->kind!=='manual')$current->campaign_id ??= $current->queue->campaign_id;
                $current->campaign=DB::table('voice_campaigns')->find($current->campaign_id,['id','name']);
                if($current->campaign_id)$current->contact = app(VoiceAudience::class)->personalize($w, $current->campaign_id, $current->contact);
                $current->call = $current->call_id ? DB::table('voice_outbound_calls')->where('id',$current->call_id)->first(['id', 'status', 'disposition_revision', 'disposition_code', 'capacity_released_at']) : null;
            }

            $team = $manage ? DB::table('users as u')->leftJoin('voice_agent_presence as p', function ($j) use ($w) { $j->on('p.user_id', '=', 'u.id')->where('p.workspace_id', $w); })->where('u.voice_workspace_id', $w)->get(['u.id','u.name','p.status','p.pause_reason','p.last_seen_at','p.available_after','p.queue_ids'])->map(function ($a) {
                $a->online = $a->last_seen_at && CarbonImmutable::parse($a->last_seen_at)->gt(now()->subSeconds(90));
                if (! $a->online) $a->status = 'offline';
                return $a;
            }) : [];
            return ['wrapup'=>app(AgentWrapup::class)->state($w,$u),'last_call'=>app(CallFeedback::class)->latest($w,$u),'manual_origins' => app(ManualDial::class)->origins($w,$u), 'queues' => $queues, 'team' => $team, 'current' => $current, 'presence' => DB::table('voice_agent_presence')->where('user_id',$u)->where('workspace_id',$w)->first(), 'user_id' => $u];
        });
    }
}
