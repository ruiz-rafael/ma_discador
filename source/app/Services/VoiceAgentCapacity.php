<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
/** Call only while holding voice_runtime row lock for a reservation. */
class VoiceAgentCapacity {
 public function inboundBusy(int $w,int $u):bool {
  return DB::table('voice_inbound_offers as o')->join('voice_inbound_calls as c','c.id','=','o.call_id')->where('c.workspace_id',$w)->where('o.user_id',$u)->whereIn('o.status',['offered','answered','transfer_pending','unknown'])->whereNull('c.capacity_released_at')->exists()
   || DB::table('voice_inbound_calls')->where('workspace_id',$w)->where('user_id',$u)->where('status','tabulation')->exists();
 }
 public function busy(int $w,int $u):bool {
  return $this->inboundBusy($w,$u)
   || DB::table('voice_outbound_calls')->where('workspace_id',$w)->where('user_id',$u)->whereNull('capacity_released_at')->exists()
   || DB::table('voice_live_reservations')->where('workspace_id',$w)->where('user_id',$u)->whereIn('status',VoiceLiveQueue::ACTIVE)->exists()
   || DB::table('voice_queue_assignments')->where('workspace_id',$w)->where('user_id',$u)->where('status','active')->exists();
 }
}
