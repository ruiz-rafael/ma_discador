<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read-only projection. Never settles reservations, reconciles providers or starts work. */
class VoiceSupervision
{
    public const RESULTS = [
        'human'=>'Pessoa identificada', 'connected'=>'Atendida · sem classificação',
        'machine'=>'Caixa postal', 'fax'=>'Fax', 'answer_unknown'=>'Atendimento inconclusivo',
        'no_answer'=>'Não atendeu', 'busy'=>'Ocupado', 'failed'=>'Falha',
        'cancelled'=>'Cancelada', 'unknown'=>'A conferir', 'dialing'=>'Discando',
        'reserved'=>'Preparando', 'waiting'=>'Aguardando agente', 'abandoned'=>'Abandonada',
        'no_agent'=>'Sem agente', 'unavailable'=>'Recepção indisponível', 'ended'=>'Encerrada',
    ];

    private function outbound(int $w)
    {
        $result = "CASE WHEN c.human_confirmed=true OR c.answer_classification='human' THEN 'human'
            WHEN c.answer_classification='machine' THEN 'machine' WHEN c.answer_classification='fax' THEN 'fax'
            WHEN c.answered_at IS NOT NULL OR c.status IN ('answered','completed') THEN
                CASE WHEN c.amd_mode!='off' THEN 'answer_unknown' ELSE 'connected' END
            WHEN c.status='pending' THEN 'reserved' ELSE c.status END";
        return DB::table('voice_outbound_calls as c')
            ->leftJoin('voice_contacts as p','p.id','=','c.contact_id')
            ->leftJoin('voice_campaigns as g','g.id','=','c.campaign_id')
            ->leftJoin('voice_live_queues as q','q.id','=','c.queue_id')
            ->leftJoin('users as u','u.id','=','c.user_id')->where('c.workspace_id',$w)
            ->selectRaw("c.id, 'outbound' as direction, c.campaign_id, c.queue_id, c.user_id,
                c.destination as phone, c.caller_id as origin, p.name as contact_name,
                g.name as campaign_name, q.name as queue_name, u.name as agent_name,
                c.status, c.started_at, c.answered_at, c.ended_at, c.created_at,
                c.capacity_released_at, c.disposition_label, c.bill_seconds,
                c.dial_status, c.cause, c.answer_classification, c.amd_mode,
                c.voicemail_status, c.method, c.context_snapshot, c.disposition_notes as notes,
                c.updated_at, {$result} as result,
                CASE WHEN c.started_at IS NOT NULL THEN 1 ELSE 0 END as attempted");
    }

    private function inbound(int $w)
    {
        return DB::table('voice_inbound_calls as c')
            ->leftJoin('voice_live_queues as q','q.id','=','c.queue_id')
            ->leftJoin('users as u','u.id','=','c.user_id')->where('c.workspace_id',$w)
            ->selectRaw("c.id, 'inbound' as direction, CAST(NULL AS BIGINT) as campaign_id, c.queue_id, c.user_id,
                c.from_number as phone, c.to_number as origin, NULL as contact_name,
                NULL as campaign_name, q.name as queue_name, u.name as agent_name,
                c.status, c.created_at as started_at, c.answered_at, c.ended_at, c.created_at,
                c.capacity_released_at, c.disposition_code as disposition_label, c.bill_seconds,
                NULL as dial_status, NULL as cause, 'not_requested' as answer_classification,
                'off' as amd_mode, 'none' as voicemail_status, 'programmable_voice' as method,
                NULL as context_snapshot, c.notes, c.updated_at,
                CASE WHEN c.answered_at IS NOT NULL THEN 'connected'
                    WHEN c.status IN ('waiting','offering') THEN 'waiting'
                    WHEN c.status='ringing' THEN 'reserved'
                    WHEN c.status IN ('abandoned','no_agent','failed','unknown','unavailable') THEN c.status
                    ELSE 'ended' END as result, 1 as attempted");
    }

    public function query(int $w, array $f = [], bool $period = true)
    {
        $q = DB::query()->fromSub($this->outbound($w)->unionAll($this->inbound($w)), 'calls');
        foreach (['queue_id','campaign_id','user_id','direction','result'] as $key) {
            if (!empty($f[$key])) $q->where($key,$f[$key]);
        }
        if (!empty($f['attempted'])) $q->where('attempted',1);
        if ($period) {
            $tz = 'America/Sao_Paulo';
            $q->whereRaw('COALESCE(started_at,created_at) >= ?', [CarbonImmutable::parse($f['from'], $tz)->startOfDay()->utc()]);
            $q->whereRaw('COALESCE(started_at,created_at) < ?', [CarbonImmutable::parse($f['to'], $tz)->addDay()->startOfDay()->utc()]);
        }
        if (!empty($f['q'])) {
            // Literal substring search, not SQL wildcard input.
            $term = '%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($f['q'])).'%';
            $q->where(fn($b)=>$b->whereRaw("LOWER(COALESCE(phone,'')) LIKE ? ESCAPE '!'",[$term])->orWhereRaw("LOWER(COALESCE(contact_name,'')) LIKE ? ESCAPE '!'",[$term])->orWhereRaw("LOWER(COALESCE(agent_name,'')) LIKE ? ESCAPE '!'",[$term]));
        }
        return $q;
    }

    public function row(object $c): array
    {
        $snapshot = json_decode($c->context_snapshot ?? '{}', true) ?: [];
        foreach (['contact_name','campaign_name','agent_name'] as $field) if (array_key_exists($field,$snapshot)) $c->$field=$snapshot[$field];
        unset($c->context_snapshot);
        $c->result_label=self::RESULTS[$c->result]??'Resultado não mapeado';
        foreach (['started_at','answered_at','ended_at','created_at','capacity_released_at','updated_at'] as $field) $c->$field=$this->iso($c->$field);
        return (array)$c;
    }

    private function iso(?string $value): ?string { return $value ? CarbonImmutable::parse($value)->utc()->toIso8601String() : null; }

    public function snapshot(int $w, array $f): array
    {
        $queues=DB::table('voice_live_queues')->where('workspace_id',$w)->orderBy('name')->get();
        $links=DB::table('voice_queue_campaigns')->whereIn('queue_id',$queues->pluck('id'))->get()->groupBy('queue_id');
        foreach ($queues as $q) {
            $q->campaign_ids=collect([$q->campaign_id])->merge(($links->get($q->id)??collect())->pluck('campaign_id'))->filter()->unique()->values()->all();
            $q->agent_ids=json_decode($q->agent_ids,true)?:[];
        }
        $scopeQueues=$queues->filter(fn($q)=>(empty($f['queue_id'])||$q->id===$f['queue_id'])&&(empty($f['campaign_id'])||in_array($f['campaign_id'],$q->campaign_ids,true)));
        $team=$this->team($w,$queues,$scopeQueues,!empty($f['queue_id'])||!empty($f['campaign_id']));
        $base=$this->query($w,$f);
        $summary=(clone $base)->selectRaw("COUNT(*) as records, COALESCE(SUM(attempted),0) as attempts,
            COALESCE(SUM(CASE WHEN answered_at IS NOT NULL OR status='completed' THEN 1 ELSE 0 END),0) as connected,
            COALESCE(SUM(CASE WHEN result='human' THEN 1 ELSE 0 END),0) as humans,
            COALESCE(SUM(CASE WHEN result='machine' THEN 1 ELSE 0 END),0) as machines,
            COALESCE(SUM(CASE WHEN result='no_answer' THEN 1 ELSE 0 END),0) as no_answer,
            COALESCE(SUM(CASE WHEN result='failed' THEN 1 ELSE 0 END),0) as failed")->first();
        $results=(clone $base)->selectRaw('result, COUNT(*) as total')->groupBy('result')->get()->map(fn($r)=>['key'=>$r->result,'label'=>self::RESULTS[$r->result]??$r->result,'total'=>(int)$r->total]);
        $campaigns=DB::table('voice_campaigns')->where('workspace_id',$w)->get(['id','name','status']);
        $campaignTotals=(clone $base)->whereNotNull('campaign_id')->selectRaw('campaign_id, COUNT(*) as total, SUM(attempted) as attempts')->groupBy('campaign_id')->get()->keyBy('campaign_id');
        $live=$this->query($w,array_intersect_key($f,array_flip(['queue_id','campaign_id'])),false)->whereNull('capacity_released_at');
        $queuePerformance=(clone $base)->whereNotNull('queue_id')->selectRaw("queue_id,
            COALESCE(SUM(attempted),0) as attempts,
            COALESCE(SUM(CASE WHEN result='human' THEN 1 ELSE 0 END),0) as humans,
            COALESCE(SUM(CASE WHEN result='machine' THEN 1 ELSE 0 END),0) as machines,
            COALESCE(SUM(CASE WHEN result='no_answer' THEN 1 ELSE 0 END),0) as no_answer,
            COALESCE(SUM(CASE WHEN result='abandoned' THEN 1 ELSE 0 END),0) as abandoned")
            ->groupBy('queue_id')->get()->keyBy('queue_id');
        $queueLive=(clone $live)->whereNotNull('queue_id')->selectRaw("queue_id, COUNT(*) as total,
            COALESCE(SUM(CASE WHEN result IN ('reserved','dialing') THEN 1 ELSE 0 END),0) as dialing,
            COALESCE(SUM(CASE WHEN answered_at IS NOT NULL OR status='answered' THEN 1 ELSE 0 END),0) as connected")
            ->groupBy('queue_id')->get()->keyBy('queue_id');
        $queueRows=$scopeQueues->map(function($q)use($team,$w,$queuePerformance,$queueLive){
            $members=$team->filter(fn($a)=>$a['queues']->contains(fn($assigned)=>$assigned['id']===$q->id));
            $selected=$members->filter(fn($a)=>in_array($q->id,$a['selected_queue_ids'],true));
            $performance=$queuePerformance->get($q->id);$liveNow=$queueLive->get($q->id);
            return ['id'=>$q->id,'name'=>$q->name,'status'=>$q->status,'inbound_enabled'=>(bool)$q->inbound_enabled,'direction'=>$q->direction,'revision'=>$q->revision,
                'voice_number'=>$q->voice_number,'mode'=>$q->mode,'method'=>$q->calling_method,'campaign_ids'=>$q->campaign_ids,
                'assigned'=>count($q->agent_ids),'online'=>$selected->where('connected',true)->count(),'available'=>$selected->where('state','idle')->count(),
                'busy'=>$selected->whereIn('state',['connected','manual','dialing','reserved','review','tabulation','wrapup','machine'])->count(),
                'agent_states'=>['talking'=>$selected->whereIn('state',['connected','manual'])->count(),'dialing'=>$selected->whereIn('state',['dialing','reserved'])->count(),
                    'wrapup'=>$selected->whereIn('state',['wrapup','tabulation'])->count(),'paused'=>$members->where('state','paused')->count(),'offline'=>$members->where('state','offline')->count(),
                    'not_selected'=>$members->filter(fn($a)=>$a['connected']&&!in_array($q->id,$a['selected_queue_ids'],true))->count()],
                'live'=>['total'=>(int)($liveNow?->total??0),'dialing'=>(int)($liveNow?->dialing??0),'connected'=>(int)($liveNow?->connected??0)],
                'performance'=>['attempts'=>(int)($performance?->attempts??0),'humans'=>(int)($performance?->humans??0),'machines'=>(int)($performance?->machines??0),
                    'no_answer'=>(int)($performance?->no_answer??0),'abandoned'=>(int)($performance?->abandoned??0)],
                'schedule_open'=>QueueOperationSettings::open($q)];
        })->values();
        return ['generated_at'=>now()->toIso8601String(),'timezone'=>'America/Sao_Paulo','filters'=>$f,
            'summary'=>$summary,'results'=>$results,'agents'=>$team->values(),'queues'=>$queueRows,
            'agent_counts'=>$team->countBy('state'),'live_total'=>(clone $live)->count(),
            'live_calls'=>(clone $live)->orderByDesc('created_at')->limit(100)->get()->map(fn($c)=>$this->row($c)),
            'campaigns'=>$campaigns->filter(fn($c)=>(empty($f['campaign_id'])||$c->id===$f['campaign_id'])&&(empty($f['queue_id'])||$scopeQueues->contains(fn($q)=>in_array($c->id,$q->campaign_ids,true))))->map(fn($c)=>['id'=>$c->id,'name'=>$c->name,'status'=>$c->status,'attempts'=>(int)($campaignTotals->get($c->id)?->attempts??0)])->values(),
            'catalog'=>['queues'=>$queues->map(fn($q)=>['id'=>$q->id,'name'=>$q->name]),'campaigns'=>$campaigns],
            'result_options'=>collect(self::RESULTS)->map(fn($label,$key)=>['key'=>$key,'label'=>$label])->values(),
            'operation_paused'=>(bool)app(OperationPolicy::class)->get($w)['paused']];
    }

    private function team(int $w, $queues, $scopeQueues, bool $filtered)
    {
        $users=DB::table('users')->where('voice_workspace_id',$w)->where('voice_enabled',true)->get(['id','name','voice_role']);
        $presence=DB::table('voice_agent_presence')->where('workspace_id',$w)->get()->keyBy('user_id');
        $open=$this->query($w,[],false)->whereNull('capacity_released_at')->orderByDesc('created_at')->get()->groupBy('user_id');
        $lastEnd=$this->query($w,[],false)->whereNotNull('ended_at')->selectRaw('user_id, MAX(ended_at) as ended')->groupBy('user_id')->get()->keyBy('user_id');
        $res=DB::table('voice_live_reservations')->where('workspace_id',$w)->whereIn('status',VoiceLiveQueue::ACTIVE)->orderByDesc('created_at')->get()->groupBy('user_id');
        $offers=DB::table('voice_inbound_offers as o')->join('voice_inbound_calls as c','c.id','=','o.call_id')->where('c.workspace_id',$w)->whereNull('c.capacity_released_at')->whereIn('o.status',['offered','answered','transfer_pending','unknown'])->orderByDesc('o.created_at')->get(['o.user_id','o.created_at','o.answered_at','o.status','c.id','c.queue_id','c.from_number'])->groupBy('user_id');
        $tabs=DB::table('voice_inbound_calls')->where('workspace_id',$w)->where('status','tabulation')->get()->keyBy('user_id');
        $sim=DB::table('voice_queue_assignments')->where('workspace_id',$w)->where('status','active')->get()->keyBy('user_id');
        return $users->filter(fn($u)=>!$filtered||$scopeQueues->contains(fn($q)=>in_array($u->id,$q->agent_ids,true)))->map(function($u)use($queues,$presence,$open,$lastEnd,$res,$offers,$tabs,$sim){
            $p=$presence->get($u->id);$call=$open->get($u->id)?->first();$r=$res->get($u->id)?->first();$offer=$offers->get($u->id)?->first();
            $fresh=$p?->last_seen_at&&CarbonImmutable::parse($p->last_seen_at)->gt(now()->subSeconds(90));
            $assigned=$queues->filter(fn($q)=>in_array($u->id,$q->agent_ids,true));
            $selected=$assigned->filter(fn($q)=>AgentAvailability::selected($p,$q->id))->pluck('id')->all();
            $state='offline';$since=$p?->state_changed_at;$detail='Indisponível para novas distribuições';$until=null;
            if ($call) {
                $state=match($call->result){'unknown'=>'review','machine'=>'machine','reserved'=>'reserved','dialing'=>'dialing',default=>($call->answered_at?'connected':'review')};
                if($call->status==='unknown')$state='review';
                if($state==='connected'&&$call->direction==='outbound'&&$call->campaign_id===null)$state='manual';
                $since=$call->answered_at??$call->started_at??$call->created_at;
                $detail=$call->direction==='inbound'?'Chamada recebida':($state==='manual'?'Ligação manual':'Ligação de saída');
                if($call->result==='answer_unknown')$detail='Atendimento técnico · identificação inconclusiva';
            } elseif ($offer) {$state=$offer->status==='answered'?'connected':($offer->status==='unknown'?'review':'reserved');$since=$offer->answered_at??$offer->created_at;$detail='Oferta receptiva';}
            elseif($tabs->has($u->id)||($r&&$r->status==='tabulation')){$state='tabulation';$since=$tabs->get($u->id)?->ended_at??$r?->updated_at;$detail='Aguardando registro do resultado';}
            elseif($r&&in_array($r->status,['reserved','calling'])&&CarbonImmutable::parse($r->expires_at)->isFuture()){$state='reserved';$since=$r->created_at;$detail=$r->kind==='manual'?'Preparando ligação manual':'Contato reservado';}
            elseif($sim->has($u->id)){$state='reserved';$since=$sim->get($u->id)->created_at;$detail='Atendimento de laboratório';}
            elseif($fresh&&$p->status!=='offline'){
                if($p->available_after&&CarbonImmutable::parse($p->available_after)->isFuture()){$state='wrapup';$until=$this->iso($p->available_after);$since=$lastEnd->get($u->id)?->ended;$detail='Pós-atendimento da fila';}
                elseif($p->status==='paused'){$state='paused';$detail=$p->pause_reason?:'Pausa do atendente';}
                else{$state='idle';$since=max($p->available_since??'', $p->available_after??'', $lastEnd->get($u->id)?->ended??'')?:null;$detail=$selected?'Aguardando distribuição nas filas selecionadas':'Online · nenhuma fila selecionada';}
            } elseif($p&&$p->status!=='offline'){$since=$p->last_seen_at?CarbonImmutable::parse($p->last_seen_at)->addSeconds(90)->toDateTimeString():null;$detail='Conexão sem atualização há mais de 90 segundos';}
            return ['id'=>$u->id,'name'=>$u->name,'state'=>$state,'since'=>$this->iso($since),'until'=>$until,'detail'=>$detail,
                'connected'=>(bool)$fresh,'selected_queue_ids'=>$selected,'queues'=>$assigned->map(fn($q)=>['id'=>$q->id,'name'=>$q->name])->values(),
                'call'=>$call?$this->row(clone $call):null];
        });
    }

    public function calls(int $w,array $f): array
    {
        $q=$this->query($w,$f);
        if(!empty($f['live']))$q=$this->query($w,$f,false)->whereNull('capacity_released_at');
        $page=$q->orderByDesc('created_at')->orderBy('id')->paginate(25,['*'],'page',$f['page']??1);
        $page->setCollection($page->getCollection()->map(fn($c)=>$this->row($c)));
        return ['calls'=>$page,'generated_at'=>now()->toIso8601String()];
    }
}
