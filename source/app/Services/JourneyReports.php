<?php
namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class JourneyReports
{
    public const METRICS = ['calls','answered','no_answer','call_failed','call_other','dispatches','delivered','read','message_failed','message_pending','replies','button_clicks','unattributed'];
    private const CALLS = [
        'calls'=>"r.started_at is not null",
        'answered'=>"r.started_at is not null and (r.answered_at is not null or r.status='completed')",
        'no_answer'=>"r.started_at is not null and r.answered_at is null and r.status='no_answer'",
        'call_failed'=>"r.answered_at is null and r.status='failed'",
        'call_other'=>"r.started_at is not null and r.answered_at is null and r.status not in ('completed','no_answer','failed')",
    ];
    private const MESSAGES = [
        'dispatches'=>"r.direction='outbound' and r.status!='cancelled'",
        'delivered'=>"r.direction='outbound' and r.status in ('delivered','read')",
        'read'=>"r.direction='outbound' and r.status='read'",
        'message_failed'=>"r.direction='outbound' and r.status in ('failed','undelivered')",
        'message_pending'=>"r.direction='outbound' and r.status not in ('cancelled','failed','undelivered','delivered','read')",
        'replies'=>"r.direction='inbound'",
        'button_clicks'=>"r.direction='inbound' and r.button_id is not null",
        'unattributed'=>"r.direction='inbound' and r.campaign_id is null",
    ];
    private function base(int $w,array $f,bool $calls)
    {
        $time=$calls?'COALESCE(r.started_at,r.created_at)':'r.created_at';
        $q=DB::table(($calls?'voice_outbound_calls':'wa_messages').' as r')->where('r.workspace_id',$w)
            ->whereRaw("$time >= ?",[CarbonImmutable::parse($f['from'],'America/Sao_Paulo')->startOfDay()->utc()])
            ->whereRaw("$time < ?",[CarbonImmutable::parse($f['to'],'America/Sao_Paulo')->addDay()->startOfDay()->utc()]);
        if (!empty($f['campaign_id'])) $q->where('r.campaign_id',$f['campaign_id']);
        if (!empty($f['day'])) {
            $q->whereRaw("$time >= ?",[CarbonImmutable::parse($f['day'],'America/Sao_Paulo')->startOfDay()->utc()])
                ->whereRaw("$time < ?",[CarbonImmutable::parse($f['day'],'America/Sao_Paulo')->addDay()->startOfDay()->utc()]);
        }
        if (!empty($f['phone'])) $q->where($calls?'r.destination':DB::raw("case when r.direction='inbound' then r.from_number else r.to_number end"),'like','%'.$f['phone'].'%');
        return $q;
    }
    private function aggregates(array $metrics): string
    {
        return implode(',',array_map(fn($key,$sql)=>"coalesce(sum(case when $sql then 1 else 0 end),0) as $key",array_keys($metrics),$metrics));
    }
    private function numbers(object $row): array
    {
        return array_map(fn($v)=>(int)$v,(array)$row);
    }
    private function dayExpression(bool $calls): string
    {
        $col=$calls?'COALESCE(r.started_at,r.created_at)':'r.created_at';
        return DB::getDriverName()==='pgsql'?"date(timezone('America/Sao_Paulo', $col at time zone 'UTC'))":"date($col, '-3 hours')";
    }
    public function dashboard(int $w,array $f): array
    {
        $calls=$this->base($w,$f,true);$messages=$this->base($w,$f,false);
        $summary=$this->numbers((clone $calls)->selectRaw($this->aggregates(self::CALLS))->first())
            +$this->numbers((clone $messages)->selectRaw($this->aggregates(self::MESSAGES))->first());
        $summary['called_contacts']=(clone $calls)->whereNotNull('r.started_at')->distinct()->count('r.contact_id');
        $summary['answered_contacts']=(clone $calls)->whereRaw(self::CALLS['answered'])->distinct()->count('r.contact_id');
        $summary['reply_contacts']=(clone $messages)->where('r.direction','inbound')->distinct()->count('r.from_number');
        $summary['button_contacts']=(clone $messages)->whereRaw(self::MESSAGES['button_clicks'])->distinct()->count('r.from_number');
        $summary['human_contacts']=(clone $calls)->where('r.human_confirmed',true)->distinct()->count('r.contact_id');
        $series=[];
        foreach([[true,$calls,self::CALLS],[false,$messages,self::MESSAGES]] as [$isCall,$q,$metrics]) {
            $day=$this->dayExpression($isCall);
            foreach((clone $q)->selectRaw("$day as day,".$this->aggregates($metrics))->groupByRaw($day)->get() as $r) {
                $date=$r->day;unset($r->day);$series[$date]=array_merge($series[$date]??[],$this->numbers($r));
            }
        }
        $timeline=[];$start=CarbonImmutable::parse($f['from']);$end=CarbonImmutable::parse($f['to']);
        for($day=$start;$day->lte($end);$day=$day->addDay())$timeline[]=array_merge(array_fill_keys(self::METRICS,0),$series[$day->toDateString()]??[],['day'=>$day->toDateString()]);
        $buttons=(clone $messages)->whereRaw(self::MESSAGES['button_clicks'])->selectRaw('r.button_id,r.button_label,count(*) as clicks,count(distinct r.from_number) as contacts')
            ->groupBy('r.button_id','r.button_label')->orderByDesc('clicks')->orderBy('r.button_id')->limit(100)->get();
        $groups=[];
        foreach([[$calls,self::CALLS],[$messages,self::MESSAGES]] as [$q,$metrics])foreach((clone $q)->selectRaw('r.campaign_id,'.$this->aggregates($metrics))->groupBy('r.campaign_id')->get() as $r) {
            $id=$r->campaign_id??0;unset($r->campaign_id);$groups[$id]=array_merge($groups[$id]??[],$this->numbers($r));
        }
        $campaigns=DB::table('voice_campaigns')->where('workspace_id',$w)->when(!empty($f['campaign_id']),fn($q)=>$q->where('id',$f['campaign_id']))->orderByDesc('updated_at')->get(['id','name','status']);
        $comparison=$campaigns->map(fn($c)=>array_merge(array_fill_keys(self::METRICS,0),$groups[$c->id]??[],(array)$c))->values()->all();
        if(isset($groups[0]))$comparison[]=array_merge(array_fill_keys(self::METRICS,0),$groups[0],['id'=>null,'name'=>'Sem jornada atribuída / avulsas','status'=>null]);
        return ['summary'=>$summary,'timeline'=>$timeline,'buttons'=>$buttons,'journeys'=>$comparison,'filters'=>$f,'generated_at'=>now()->toIso8601String(),
            'attribution'=>['inferred'=>(clone $messages)->where('r.direction','inbound')->where('r.reply_attribution','single_journey')->count(),'unattributed'=>$summary['unattributed']],
            'capabilities'=>['reply_buttons'=>true,'url_clicks'=>false]];
    }
    public function details(int $w,array $f): array
    {
        $metric=$f['metric'];$call=isset(self::CALLS[$metric]);
        $q=$this->base($w,$f,$call)->whereRaw((self::CALLS+self::MESSAGES)[$metric]);
        if(!empty($f['button_id']))$q->where('r.button_id',$f['button_id']);
        if(isset($f['button_label']))$q->where('r.button_label',$f['button_label']);
        $q->leftJoin('voice_contacts as p','p.id','=','r.contact_id')->leftJoin('voice_campaigns as c','c.id','=','r.campaign_id');
        $fields=$call?['r.id','r.contact_id','r.campaign_id','r.status','r.destination','r.caller_id','r.method','r.bill_seconds','r.started_at','r.answered_at','r.ended_at','r.created_at','r.disposition_label','r.human_confirmed','r.disposition_notes','r.context_snapshot']
            :['r.id','r.contact_id','r.campaign_id','r.direction','r.from_number','r.to_number','r.status','r.error_code','r.body','r.provider','r.created_at','r.updated_at','r.button_id','r.button_label','r.reply_attribution','r.reply_to_message_id'];
        $rows=$q->select(array_merge($fields,['p.name as contact_name','c.name as campaign_name']))
            ->selectRaw('(select mc.name from contacts mc join voice_audience_contacts a on a.contact_id=mc.id join voice_campaign_policies cp on cp.audience_id=a.audience_id where cp.campaign_id=r.campaign_id and a.voice_contact_id=r.contact_id order by mc.id limit 1) as audience_name')
            ->orderByDesc($call?DB::raw('COALESCE(r.started_at,r.created_at)'):'r.created_at')->orderBy('r.id')->paginate(25,['*'],'page',(int)($f['page']??1));
        foreach($rows as $row){
            $snapshot=json_decode($row->context_snapshot??'{}',true);
            $row->contact_name=$snapshot['contact_name']??$row->audience_name??$row->contact_name??'Contato não cadastrado';
            unset($row->context_snapshot,$row->audience_name);
            $row->kind=$call?'calls':'messages';
        }
        return ['rows'=>$rows,'metric'=>$metric,'filters'=>$f];
    }
    public function record(int $w,string $kind,string $id): array
    {
        $call=$kind==='calls';
        $m=DB::table($call?'voice_outbound_calls':'wa_messages')->where('workspace_id',$w)->where('id',$id)->firstOrFail();
        // Only public report fields. Never expose call grants, hashes, provider credentials or tokens.
        $fields=$call?['id','contact_id','campaign_id','status','destination','caller_id','method','bill_seconds','created_at','started_at','answered_at','ended_at','dial_status','cause','disposition_label','human_confirmed','disposition_notes','context_snapshot']
            :['id','contact_id','campaign_id','direction','provider','status','from_number','to_number','body','variables','interactive','error_code','created_at','updated_at','button_id','button_label','reply_attribution','reply_to_message_id'];
        $record=array_intersect_key((array)$m,array_flip($fields));
        foreach(['variables','interactive','context_snapshot'] as $key)if(isset($record[$key]))$record[$key]=json_decode($record[$key],true);
        $events=$call?DB::table('voice_dispositions')->where('workspace_id',$w)->where('call_id',$id)->orderBy('revision')->limit(100)->get(['label','notes','human','revision','created_at'])
            :DB::table('wa_events')->where('message_id',$id)->orderBy('created_at')->limit(100)->get(['status','created_at']);
        $replies=$call?collect():DB::table('wa_messages')->where('workspace_id',$w)->where('reply_to_message_id',$id)->orderBy('created_at')->limit(100)->get(['id','body','button_id','button_label','reply_attribution','created_at']);
        $parent=(!$call&&$m->reply_to_message_id)?DB::table('wa_messages')->where('workspace_id',$w)->where('id',$m->reply_to_message_id)->first(['id','body','created_at','interactive']):null;
        return ['record'=>$record,'events'=>$events,'replies'=>$replies,'original_message'=>$parent];
    }
}
