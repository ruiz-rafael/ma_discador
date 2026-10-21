<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class OperationPolicy {
 public function get(int $w):array{$p=DB::table('ma_operation_policy')->where('workspace_id',$w)->first();return $p?array_replace((array)$p,['voice_daily_limit'=>null]):['workspace_id'=>$w,'paused'=>false,'voice_daily_limit'=>null,'whatsapp_daily_limit'=>100,'cost_alert_usd'=>null,'revision'=>0];}
 public function outboundToday(?int $w=null):int {
  // A released reservation cancelled before dialing is not a telephone attempt.
  // Pending, uncertain, and started calls continue consuming the existing limit.
  return DB::table('voice_outbound_calls')->when($w!==null,fn($q)=>$q->where('workspace_id',$w))->where('created_at','>=',now()->startOfDay())
   ->where(fn($q)=>$q->where('status','!=','cancelled')->orWhereNotNull('started_at')->orWhereNull('capacity_released_at'))->count();
 }
 public function inboundReason(int $w):?string {
  return $this->get($w)['paused']?'Novos atendimentos estão pausados pela supervisão.':null;
 }
 /** Platform daily voice quotas are retired; explicit operational pause still applies. */
 public function voiceReason(int $w):?string {return $this->inboundReason($w);}
 public function messages(int $w):void{$p=$this->get($w);abort_if($p['paused'],409,'Novos envios estão pausados pela supervisão.');abort_if(DB::table('wa_messages')->where('workspace_id',$w)->where('direction','outbound')->where('created_at','>=',now()->startOfDay())->count()>=$p['whatsapp_daily_limit'],429,'Limite diário compartilhado de WhatsApp atingido.');}
}
