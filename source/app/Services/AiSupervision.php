<?php
namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only observations. A closed session is not proof that a problem was solved. */
class AiSupervision
{
    public const TIMEZONE='America/Sao_Paulo';
    public const ACTIVE=['open','connecting','closing','transferring'];

    private function condition(Builder $query,string $outcome): void
    {
        $query->where(function(Builder $q) use($outcome){
            if($outcome==='active')$q->whereIn('s.status',self::ACTIVE)->where('s.expires_at','>',now());
            elseif($outcome==='expired')$q->whereIn('s.status',self::ACTIVE)->where('s.expires_at','<=',now());
            elseif($outcome==='handoff'){
                $q->whereIn('s.status',['handoff','transferring','transferred'])
                    ->orWhereExists(fn($t)=>$t->selectRaw('1')->from('ai_turns as t')->whereColumn('t.session_id','s.id')->where('t.action','handoff')->where('t.status','completed'))
                    ->orWhereExists(fn($j)=>$j->selectRaw('1')->from('ai_whatsapp_jobs as j')->whereColumn('j.session_id','s.id')->whereColumn('j.workspace_id','s.workspace_id')->where('j.status','handoff'))
                    ->orWhereExists(fn($a)=>$a->selectRaw('1')->from('voice_audit as a')->whereRaw('a.subject_id = CAST(s.id AS TEXT)')->whereColumn('a.workspace_id','s.workspace_id')->where('a.event','ai.voice.transfer'));
            }elseif($outcome==='failure'){
                $q->where('s.status','failed')
                    ->orWhereExists(fn($t)=>$t->selectRaw('1')->from('ai_turns as t')->whereColumn('t.session_id','s.id')->where('t.status','failed'))
                    ->orWhereExists(fn($j)=>$j->selectRaw('1')->from('ai_whatsapp_jobs as j')->whereColumn('j.session_id','s.id')->whereColumn('j.workspace_id','s.workspace_id')->whereIn('j.status',['failed','unknown']))
                    ->orWhereExists(fn($a)=>$a->selectRaw('1')->from('voice_audit as a')->whereRaw('a.subject_id = CAST(s.id AS TEXT)')->whereColumn('a.workspace_id','s.workspace_id')->where('a.event','ai.voice.transfer')->where('a.data->request_accepted',false));
            }elseif($outcome==='stop'){
                $q->where('s.status','stop')->orWhereExists(fn($t)=>$t->selectRaw('1')->from('ai_turns as t')->whereColumn('t.session_id','s.id')->where('t.action','stop')->where('t.status','completed'));
            }else $q->where('s.status','closed');
        });
    }

    private function query(int $w,array $f): Builder
    {
        $from=CarbonImmutable::parse($f['from'],self::TIMEZONE)->startOfDay()->utc();
        $until=CarbonImmutable::parse($f['to'],self::TIMEZONE)->addDay()->startOfDay()->utc();
        $q=DB::table('ai_sessions as s')->where('s.workspace_id',$w)->where('s.created_at','>=',$from)->where('s.created_at','<',$until);
        $q->whereIn('s.channel',$f['scope']==='production'?['whatsapp','phone']:['text','voice']);
        if(!empty($f['agent_id']))$q->where('s.agent_id',$f['agent_id']);
        if(!empty($f['channel']))$q->where('s.channel',$f['channel']);
        if(!empty($f['outcome']))$this->condition($q,$f['outcome']);
        return $q;
    }

    public function snapshot(int $w,array $f): array
    {
        $q=$this->query($w,$f);
        $summary=['sessions'=>(clone $q)->count()];
        foreach(['active','handoff','failure','stop','closed','expired'] as $key){$count=clone $q;$this->condition($count,$key);$summary[$key]=$count->count();}
        $text=(clone $q)->where('s.mode','premium')->whereIn('s.channel',['text','whatsapp']);
        $summary['text_estimated_usd']=round((float)(clone $text)->sum('s.estimated_usd'),6);
        $summary['input_tokens']=(int)(clone $text)->sum('s.input_tokens');
        $summary['output_tokens']=(int)(clone $text)->sum('s.output_tokens');
        $timing=DB::table('ai_turns as t')->whereIn('t.session_id',(clone $text)->select('s.id'))->where('t.status','completed')->whereNotNull('t.latency_ms')->selectRaw('AVG(t.latency_ms) as average, COUNT(*) as samples')->first();
        $summary['text_latency_ms']=$timing->samples?round((float)$timing->average):null;
        $summary['text_latency_samples']=(int)$timing->samples;
        $summary['voice_sessions']=(clone $q)->whereIn('s.channel',['voice','phone'])->count();
        $summary['voice_estimated_usd']=null;
        $channels=(clone $q)->select('s.channel')->selectRaw('COUNT(*) as total')->groupBy('s.channel')->get()->map(fn($r)=>['channel'=>$r->channel,'total'=>(int)$r->total]);
        // Aggregate at UTC-hour granularity, then map to the display timezone in PHP.
        $hour=DB::getDriverName()==='pgsql'?"to_char(s.created_at, 'YYYY-MM-DD HH24:00:00')":"strftime('%Y-%m-%d %H:00:00', s.created_at)";
        $buckets=(clone $q)->selectRaw($hour.' as hour, COUNT(*) as total')->groupByRaw($hour)->get();
        $days=[];for($day=CarbonImmutable::parse($f['from']);$day->toDateString()<=$f['to'];$day=$day->addDay())$days[$day->toDateString()]=0;
        foreach($buckets as $b){$day=CarbonImmutable::parse($b->hour,'UTC')->setTimezone(self::TIMEZONE)->toDateString();if(array_key_exists($day,$days))$days[$day]+=(int)$b->total;}
        $series=[];foreach($days as $date=>$total)$series[]=['date'=>$date,'total'=>$total];
        $sessions=(clone $q)->leftJoin('ai_agents as a',fn($j)=>$j->on('a.id','=','s.agent_id')->on('a.workspace_id','=','s.workspace_id'))
            ->orderByDesc('s.created_at')->orderByDesc('s.id')->paginate(25,['s.id','s.agent_id','a.name as agent_name','s.channel','s.mode','s.provider','s.model','s.status','s.turns','s.estimated_usd','s.created_at','s.updated_at','s.expires_at'],'page',$f['page']??1);
        $sessions->getCollection()->transform(fn($s)=>$this->present($s));
        $agents=DB::table('ai_agents')->where('workspace_id',$w)->orderBy('name')->get(['id','name','paused','published_version']);
        $connection=app(AiProvider::class)->connection($w);
        return ['filters'=>$f,'timezone'=>self::TIMEZONE,'as_of'=>now()->toIso8601String(),'summary'=>$summary,'channels'=>$channels,'series'=>$series,'sessions'=>$sessions,'agents'=>$agents,
            'readiness'=>['configured'=>$connection['configured'],'consumption_enabled'=>$connection['enabled'],'voice_worker_ready'=>app(AiSip::class)->ready(),'published_agents'=>$agents->whereNotNull('published_version')->count()],
            'limits'=>['history_days'=>30,'voice_cost_available'=>false,'emotion_scoring'=>false]];
    }

    private function present(object $s): object
    {
        $s->expired=in_array($s->status,self::ACTIVE,true)&&CarbonImmutable::parse($s->expires_at)->lte(now());
        $s->infrastructure_cost_included=false;$s->estimated_usd=in_array($s->channel,['voice','phone'],true)?null:(float)$s->estimated_usd;
        return $s;
    }

    public function detail(int $w,string $id,int $page): array
    {
        $s=DB::table('ai_sessions')->where('workspace_id',$w)->where('id',$id)->firstOrFail();
        $config=json_decode($s->config,true);
        $meta=['name'=>$config['name']??'Agente','language'=>$config['language']??null,'adaptive_conversation'=>(bool)($config['adaptive_conversation']??false),'revision'=>$s->revision];
        // Whitelist only operational data. Never return provider IDs or full company instructions.
        $session=(object)array_intersect_key((array)$s,array_flip(['id','agent_id','channel','mode','provider','model','status','turns','summary','estimated_usd','input_tokens','output_tokens','created_at','updated_at','expires_at']));
        $turns=DB::table('ai_turns')->where('session_id',$id)->orderBy('sequence')->orderBy('created_at')->orderBy('id')->paginate(30,['id','input','output','status','action','latency_ms','created_at'],'page',$page);
        $deliveries=DB::table('ai_whatsapp_jobs as j')->where('j.workspace_id',$w)->where('j.session_id',$id)->orderByDesc('j.created_at')->limit(30)->get(['j.status','j.reason','j.created_at']);
        $transfers=DB::table('voice_audit')->where('workspace_id',$w)->where('subject_id',$id)->where('event','ai.voice.transfer')->latest('created_at')->limit(30)->get(['data','created_at'])->map(function($a){$d=json_decode($a->data,true);return ['requested_at'=>$a->created_at,'provider_accepted'=>(bool)($d['request_accepted']??false)];});
        return ['session'=>$this->present($session),'profile'=>$meta,'turns'=>$turns,'deliveries'=>$deliveries,'transfers'=>$transfers];
    }
}
