<?php
namespace App\Services;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
class QueueOperationSettings {
 public const RULES=['schedule_enabled'=>'sometimes|boolean','schedule_timezone'=>'sometimes|timezone','schedule_days'=>'sometimes|array|min:1|max:7','schedule_days.*'=>'integer|between:1,7|distinct','schedule_start'=>'sometimes|date_format:H:i','schedule_end'=>'sometimes|date_format:H:i','agent_ring_seconds'=>'sometimes|nullable|integer|between:5,60','missed_offer_limit'=>'sometimes|integer|between:0,10','pause_on_disconnect'=>'sometimes|boolean','allow_international'=>'sometimes|boolean','allow_special'=>'sometimes|boolean','recording_enabled'=>'sometimes|boolean','recording_agent_access'=>'sometimes|boolean','recording_agent_pause'=>'sometimes|boolean','recording_retention_days'=>'sometimes|integer|between:1,365'];
 public function values(array $d,?object $old):array {
  $v=array_intersect_key($d,self::RULES);if(isset($v['schedule_days']))$v['schedule_days']=json_encode(array_values($v['schedule_days']));
  $q=(object)array_merge((array)($old??(object)[]),$v);
  abort_if(($q->schedule_enabled??false)&&($q->schedule_start??'09:00')===($q->schedule_end??'18:00'),422,'Escolha horários de início e fim diferentes.');
  abort_if(($q->recording_agent_pause??false)&&!($q->recording_agent_access??false),422,'Permita ao agente visualizar a gravação antes de permitir pausar.');
  return $v;
 }
 public static function open(object $q):bool {
  if(!($q->schedule_enabled??false))return true;
  $now=CarbonImmutable::now($q->schedule_timezone);$time=$now->format('H:i');$days=json_decode($q->schedule_days??'[1,2,3,4,5,6,7]',true);$start=$q->schedule_start;$end=$q->schedule_end;
  if($start<$end)return in_array($now->isoWeekday(),$days,true)&&$time>=$start&&$time<$end;
  return ($time>=$start&&in_array($now->isoWeekday(),$days,true))||($time<$end&&in_array($now->subDay()->isoWeekday(),$days,true));
 }
 public static function check(object $q):void {abort_unless(self::open($q),409,'Fora do horário de funcionamento da fila.');}
 // Called under the same transaction/lock that consumes the offer callback.
 public function offerResult(int $queue,int $user,bool $answered):void {
  $q=DB::table('voice_live_queues')->find($queue);$row=DB::table('voice_agent_queue_counters')->where('queue_id',$queue)->where('user_id',$user)->first();$count=($answered||!$q->missed_offer_limit)?0:($row?->missed_offers??0)+1;
  DB::table('voice_agent_queue_counters')->updateOrInsert(['queue_id'=>$queue,'user_id'=>$user],['missed_offers'=>$count]);
  if(!$answered&&$q->missed_offer_limit>0&&$count>=$q->missed_offer_limit)$this->pause($q,$user,'Chamadas não atendidas: '.$count.' ofertas consecutivas na fila '.$q->name.'. Clique em Online para retomar.');
 }
 private function pause(object $q,int $u,string $reason):void {
  $changed=DB::table('voice_agent_presence')->where('workspace_id',$q->workspace_id)->where('user_id',$u)->where('status','available')->update(['status'=>'paused','pause_reason'=>$reason,'updated_at'=>now()]);
  if($changed)app(VoiceLab::class)->audit($q->workspace_id,$u,'queue.auto_paused',$q->id,['reason'=>$reason]);
 }
 public function disconnected(int $w,int $u):void {
  $p=DB::table('voice_agent_presence')->where('workspace_id',$w)->where('user_id',$u)->first();if(!$p||$p->status!=='available')return;
  foreach(DB::table('voice_live_queues')->where('workspace_id',$w)->where('pause_on_disconnect',true)->get()as $q){if(in_array($u,json_decode($q->agent_ids,true),true)&&AgentAvailability::selected($p,$q->id)){$this->pause($q,$u,'Conexão de voz perdida na fila '.$q->name.'. Confira o headset e clique em Online para reconectar.');break;}}
 }
 public function sweep():void {DB::transaction(function(){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();foreach(DB::table('voice_inbound_devices')->where(fn($q)=>$q->where('ready',false)->orWhere('last_seen_at','<=',now()->subSeconds(60)))->get()as $d)$this->disconnected($d->workspace_id,$d->user_id);});}
}
