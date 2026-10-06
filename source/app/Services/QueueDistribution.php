<?php
namespace App\Services;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
/** All decisions and cursor writes run under voice_runtime's transactional lock. */
class QueueDistribution {
 public const STRATEGIES=['channel_default','round_robin','longest_idle'];
 public function record(object $q,int $u,string $channel):void {
  DB::table('voice_live_queues')->where('id',$q->id)->increment('distribution_cursor');
  $sequence=DB::table('voice_live_queues')->where('id',$q->id)->value('distribution_cursor');
  DB::table('voice_queue_agent_turns')->updateOrInsert(['queue_id'=>$q->id,'user_id'=>$u,'channel'=>$channel],['last_sequence'=>$sequence,'assigned_at'=>now()]);
 }
 public function order(object $q,Collection $agents,string $channel):Collection {
  if(($q->distribution_strategy??'channel_default')==='channel_default')return $agents;
  $turns=DB::table('voice_queue_agent_turns')->where('queue_id',$q->id)->where('channel',$channel)->get()->keyBy('user_id');
  $idle=[];
  if($q->distribution_strategy==='longest_idle'){
   $ids=$agents->pluck('id');$presence=DB::table('voice_agent_presence')->where('workspace_id',$q->workspace_id)->whereIn('user_id',$ids)->get()->keyBy('user_id');
   foreach($ids as $id){$p=$presence->get($id);$idle[$id]=max($p?->available_since??$p?->created_at??'',$p?->available_after??'',$turns->get($id)?->assigned_at??'');}
   foreach(['voice_outbound_calls'=>'ended_at','voice_inbound_calls'=>'ended_at','voice_live_reservations'=>'finished_at']as $table=>$column){
    $ends=DB::table($table)->where('workspace_id',$q->workspace_id)->whereIn('user_id',$ids)->selectRaw('user_id,max('.$column.') as last_end')->groupBy('user_id')->get();
    foreach($ends as $row)$idle[$row->user_id]=max($idle[$row->user_id]??'',$row->last_end??'');
   }
  }
  return $agents->sort(function($a,$b)use($q,$turns,$idle){return ($q->distribution_strategy==='longest_idle'?strcmp($idle[$a->id]??'',$idle[$b->id]??''):0)?: (($turns->get($a->id)?->last_sequence??0)<=>($turns->get($b->id)?->last_sequence??0))?:($a->id<=>$b->id);})->values();
 }
 public function outboundTurn(object $q,int $u):bool {
  if(($q->distribution_strategy??'channel_default')==='channel_default'||$q->mode!=='progressive')return true;
  DB::table('voice_queue_agent_turns')->updateOrInsert(['queue_id'=>$q->id,'user_id'=>$u,'channel'=>'outbound'],['requested_at'=>now()]);
  // Consider only browsers actively asking for work. An open dialpad/diagnostic
  // stops claims, so a stale turn cannot starve the rest of the queue indefinitely.
  $requesting=DB::table('voice_queue_agent_turns')->where('queue_id',$q->id)->where('channel','outbound')->where('requested_at','>',now()->subSeconds(15))->pluck('user_id');
  $agents=DB::table('users')->where('voice_workspace_id',$q->workspace_id)->where('voice_enabled',true)->whereIn('id',json_decode($q->agent_ids,true))->whereIn('id',$requesting)->get(['id'])->filter(fn($a)=>AgentAvailability::available($q->workspace_id,$a->id,$q->id)&&!app(VoiceAgentCapacity::class)->busy($q->workspace_id,$a->id));
  return $this->order($q,$agents,'outbound')->first()?->id===$u;
 }
}
