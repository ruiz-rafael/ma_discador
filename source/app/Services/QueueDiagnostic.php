<?php
namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Bounded, read-only observations. Never refresh segments, reconcile runs or reserve work. */
class QueueDiagnostic
{
    public const SAMPLE_LIMIT = 100;
    public function inspect(int $w, int $id): array
    {
        $q=DB::table('voice_live_queues')->where('workspace_id',$w)->find($id);
        abort_unless($q,404);
        $checks=[];
        $add=function(string $code,string $status,string $title,string $action)use(&$checks){$checks[]=compact('code','status','title','action');};
        $out=$q->direction!=='inbound';$in=$q->direction!=='outbound';
        $policy=app(OperationPolicy::class)->get($w);
        $add('operation',$policy['paused']?'blocked':'ok',$policy['paused']?'Operação pausada':'Operação liberada',$policy['paused']?'Revise a pausa em Saúde e limites.':'As demais regras continuam sendo verificadas.');
        $add('schedule',QueueOperationSettings::open($q)?'ok':'blocked',QueueOperationSettings::open($q)?'Dentro do horário da fila':'Fora do horário da fila','Confira dias, horário e fuso na configuração da fila.');
        $full=app(VoiceCapacity::class)->full();
        $add('capacity',$full?'blocked':'ok',$full?'Capacidade de telefonia ocupada':'Há capacidade disponível','Chamadas pendentes de confirmação também ocupam capacidade. Confira Chamadas e resultados; não redisque sem conferir o resultado.');
        $audio=DB::table('voice_audio_sessions')->whereIn('status',['pending','connecting','active'])->where('expires_at','>',now())->exists();
        if($audio)$add('audio_test','blocked','Teste interno de áudio em andamento','Conclua o diagnóstico de áudio antes de retomar a operação.');
        if($out){
            $add('outbound',$q->status==='running'?'ok':'blocked',$q->status==='running'?'Saída habilitada':'Saída pausada','A habilitação é feita na configuração da fila, após revisar equipe, números e cadências.');
            if($q->mode!=='progressive')$add('preview','attention','Esta fila usa confirmação do atendente','No modo preview, o atendente confirma a discagem. Não é uma sequência progressiva.');
            $add('origin',$q->voice_number?'ok':'attention',$q->voice_number?'Número de saída vinculado':'Confira o número de saída','Verifique o número autorizado na configuração da fila. A autorização da operadora não é testada nesta consulta.');
            try{$reason=app(OperationPolicy::class)->voiceReason($w);if($reason&&!$policy['paused'])$add('daily_limit','blocked',$reason,'Confira limites e consumo em Saúde e limites.');}
            catch(\Throwable){$add('daily_limit','attention','Não foi possível conferir o limite de telefonia','Peça ao administrador para revisar a configuração de telefonia.');}
        }
        if($in){
            $add('inbound',$q->inbound_enabled?'ok':'blocked',$q->inbound_enabled?'Entrada habilitada':'Entrada pausada','A disponibilidade para chamadas recebidas é controlada na fila.');
            $routes=DB::table('voice_inbound_routes')->where('workspace_id',$w)->where('queue_id',$id)->where('enabled',true)->count();
            $add('routes',$routes?'ok':'blocked',$routes?'Há rota de entrada ativa':'Nenhuma rota de entrada ativa','Vincule um número em Receptivo e confira sua rota. A conexão externa depende da operadora.');
        }
        $agents=[];$counts=['available'=>0,'offline'=>0,'paused'=>0,'unselected'=>0,'wrapup'=>0,'busy'=>0,'disabled'=>0,'inbound_ready'=>0];
        $assigned=array_values(array_unique(array_map('intval',json_decode($q->agent_ids,true)?:[])));
        $users=DB::table('users')->where('voice_workspace_id',$w)->whereIn('id',$assigned)->orderBy('id')->limit(201)->get(['id','name','voice_enabled']);
        $partialAgents=$users->count()>200;
        $presence=DB::table('voice_agent_presence')->where('workspace_id',$w)->whereIn('user_id',$users->pluck('id'))->get()->keyBy('user_id');
        $devices=DB::table('voice_inbound_devices')->where('workspace_id',$w)->whereIn('user_id',$users->pluck('id'))->get()->keyBy('user_id');
        foreach($users->take(200)as $u){
            $p=$presence->get($u->id);$state='available';$detail='Disponível nesta fila';
            if(!$u->voice_enabled){$state='disabled';$detail='Acesso desativado';}
            elseif(!$p||!$p->last_seen_at||CarbonImmutable::parse($p->last_seen_at)->lte(now()->subSeconds(90))||$p->status==='offline'){$state='offline';$detail='Offline ou navegador sem presença recente';}
            elseif($p->status==='paused'){$state='paused';$detail='Atendente em pausa';}
            elseif(!AgentAvailability::selected($p,$id)){$state='unselected';$detail='Esta fila não está selecionada no headset';}
            elseif(app(VoiceAgentCapacity::class)->busy($w,$u->id)){$state='busy';$detail='Em reserva, chamada ou tabulação';}
            elseif($p->available_after&&CarbonImmutable::parse($p->available_after)->isFuture()){$state='wrapup';$detail='Em pós-atendimento';}
            $d=$devices->get($u->id);$ready=$state==='available'&&$d&&$d->ready&&$d->last_seen_at&&CarbonImmutable::parse($d->last_seen_at)->gt(now()->subSeconds(45));
            $counts[$state]++;if($ready)$counts['inbound_ready']++;
            $agents[]=['id'=>$u->id,'name'=>$u->name,'state'=>$state,'detail'=>$detail,'inbound_ready'=>(bool)$ready];
        }
        $add('agents',$counts['available']?'ok':($partialAgents?'attention':'blocked'),$counts['available'].' atendente(s) disponível(is) nesta fila','O atendente usa Online no headset e seleciona esta fila em Gerenciar filas. Ocupação e pós-atendimento impedem novas distribuições.');
        if($in)$add('inbound_agents',$counts['inbound_ready']?'ok':($partialAgents?'attention':'blocked'),$counts['inbound_ready'].' atendente(s) com áudio receptivo pronto','Além de Online, o navegador precisa manter o receptivo conectado. Confira o áudio no headset.');
        $audience=['total'=>0,'evaluated'=>0,'eligible'=>0,'blocked'=>0,'partial'=>false,'limit'=>self::SAMPLE_LIMIT,'reasons'=>[],'campaigns'=>[]];
        if($out){
            $ids=QueueRouting::campaigns($q);
            $campaigns=DB::table('voice_campaigns')->where('workspace_id',$w)->whereIn('id',$ids)->orderBy('id')->get(['id','name','status','settings']);
            $add('campaigns',$campaigns->isEmpty()?'blocked':($campaigns->where('status','testing')->isEmpty()?'blocked':'ok'),$campaigns->where('status','testing')->count().' cadência(s) habilitada(s)','Vincule e habilite a cadência pela tela de Cadências. Cada uma mantém seus horários e tentativas.');
            $members=DB::table('voice_members as m')->join('voice_contacts as c','c.id','=','m.contact_id')->where('c.workspace_id',$w)->whereIn('m.campaign_id',$campaigns->pluck('id'));
            $audience['total']=(clone $members)->count();
            $sample=(clone $members)->orderBy('m.campaign_id')->orderBy('c.id')->limit(self::SAMPLE_LIMIT)->get(['c.*','m.campaign_id as diagnostic_campaign']);
            $byCampaign=$campaigns->keyBy('id');$groups=[];
            foreach($sample as $c){
                $campaign=$byCampaign->get($c->diagnostic_campaign);$s=json_decode($campaign->settings,true);
                $valid=is_array($s)&&!array_diff(['timezone','days','start_time','end_time','max_attempts','retry_minutes'],array_keys($s));
                $reason=$valid?(QueueDestinations::reason($q,$c->phone)??app(VoiceEligibility::class)->reason($w,$campaign,$c,null,false)):'Configuração da cadência incompleta; revise e salve a cadência.';
                if(!$reason&&(DB::table('voice_live_reservations')->where('workspace_id',$w)->where('contact_id',$c->id)->whereIn('status',VoiceLiveQueue::ACTIVE)->exists()||DB::table('voice_queue_assignments as a')->join('voice_queue_items as i','i.id','=','a.item_id')->where('a.workspace_id',$w)->where('i.contact_id',$c->id)->where('a.status','active')->exists()))$reason='Contato reservado para outro atendimento.';
                $audience['evaluated']++;
                if(!$reason){$audience['eligible']++;continue;}
                $audience['blocked']++;$groups[$reason]??=['reason'=>$reason,'count'=>0,'examples'=>[]];$groups[$reason]['count']++;
                if(count($groups[$reason]['examples'])<3)$groups[$reason]['examples'][]=['name'=>$c->name,'phone'=>$c->phone,'campaign'=>$campaign->name];
            }
            $audience['partial']=$audience['evaluated']<$audience['total'];$audience['reasons']=array_values($groups);
            usort($audience['reasons'],fn($a,$b)=>$b['count']<=>$a['count']);
            $audience['campaigns']=$campaigns->take(50)->map(fn($c)=>['id'=>$c->id,'name'=>$c->name,'enabled'=>$c->status==='testing'])->values()->all();
            $add('audience',$audience['eligible']?'ok':($audience['partial']?'attention':'blocked'),$audience['eligible'].' participação(ões) sem impedimento na amostra','Confira os motivos abaixo. O diagnóstico usa o público já registrado; segmentos e reentradas serão reavaliados pelo distribuidor.');
        }
        $blocked=count(array_filter($checks,fn($c)=>$c['status']==='blocked'));
        $attention=count(array_filter($checks,fn($c)=>$c['status']==='attention'));
        return ['generated_at'=>now()->toIso8601String(),'queue'=>['id'=>$q->id,'name'=>$q->name,'direction'=>$q->direction],'status'=>$blocked?'blocked':($attention?'attention':'checks_passed'),'checks'=>$checks,'agents'=>['counts'=>$counts,'data'=>$agents,'partial'=>$partialAgents],'audience'=>$audience,'scope'=>'Consulta dos registros atuais. Não verifica operadora, mídia, números permitidos pela rota nem sincroniza segmentos ou reentradas. A discagem aplica novamente todas as regras. Nenhuma chamada é iniciada.'];
    }
}
