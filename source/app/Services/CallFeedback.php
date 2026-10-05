<?php
namespace App\Services;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
class CallFeedback {
 public function latest(int $w,int $u):?array {
  $c=DB::table('voice_outbound_calls')->where('workspace_id',$w)->where('user_id',$u)->orderByDesc('created_at')->orderByDesc('id')->first();
  if(!$c||!in_array($c->status,['no_answer','busy','failed','cancelled','completed','unknown'],true))return null;
  $seconds=$c->started_at&&$c->ended_at?(int)abs(CarbonImmutable::parse($c->ended_at)->diffInSeconds(CarbonImmutable::parse($c->started_at))):null;
  $title=match($c->status){'no_answer'=>'Ligação sem atendimento','busy'=>'Destino ocupado','failed'=>'Não foi possível completar a ligação','cancelled'=>'Ligação cancelada','completed'=>'Ligação atendida e encerrada',default=>'Resultado ainda não confirmado'};
  $detail=match($c->status){'no_answer'=>'A operadora retornou “não atendeu”. Esse resultado não confirma que o aparelho chegou a tocar.','busy'=>'A operadora informou que o destino estava ocupado.','failed'=>'A telefonia confirmou uma falha ao completar a chamada. Consulte os detalhes técnicos com a supervisão.','cancelled'=>$c->started_at?'A ligação foi cancelada.':'A conexão foi encerrada ou expirou antes da discagem para o telefone.','completed'=>'A telefonia confirmou o atendimento e o encerramento.','unknown'=>'Aguardando confirmação da telefonia. Uma nova ligação permanece bloqueada até a conciliação.'};
  return ['id'=>$c->id,'status'=>$c->status,'title'=>$title,'detail'=>$detail,'destination'=>$c->destination,'caller_id'=>$c->caller_id,'created_at'=>$c->created_at,'elapsed_seconds'=>$seconds,'bill_seconds'=>$c->bill_seconds,'dial_status'=>$c->dial_status,'cause'=>$c->cause];
 }
}
