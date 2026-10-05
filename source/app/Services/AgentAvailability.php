<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;
class AgentAvailability {
 public static function selected(?object $presence,int $queue):bool {return $presence&&($presence->queue_ids===null||in_array($queue,json_decode($presence->queue_ids,true),true));}
 public static function available(int $w,int $u,int $queue):bool {$p=DB::table('voice_agent_presence')->where('workspace_id',$w)->where('user_id',$u)->first();return $p&&$p->status==='available'&&$p->last_seen_at&&CarbonImmutable::parse($p->last_seen_at)->gt(now()->subSeconds(90))&&self::selected($p,$queue);}
 public static function scope($query,int $queue,string $alias='p') {return $query->where(fn($q)=>$q->whereNull($alias.'.queue_ids')->orWhereJsonContains($alias.'.queue_ids',$queue));}
 public static function validateSelection(int $w,int $u,?array $ids):void {if($ids===null)return;$assigned=DB::table('voice_live_queues')->where('workspace_id',$w)->get()->filter(fn($q)=>in_array($u,json_decode($q->agent_ids,true),true))->pluck('id')->all();abort_if(array_diff($ids,$assigned),403,'Você só pode ficar online nas filas atribuídas pelo administrador.');}
}
