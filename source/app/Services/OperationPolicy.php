<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class OperationPolicy {
 public function get(int $w):array{$p=DB::table('ma_operation_policy')->where('workspace_id',$w)->first();return $p?(array)$p:['workspace_id'=>$w,'paused'=>false,'voice_daily_limit'=>50,'whatsapp_daily_limit'=>100,'cost_alert_usd'=>null,'revision'=>0];}
 public function voiceReason(int $w):?string{$p=$this->get($w);if($p['paused'])return 'Novos atendimentos estão pausados pela supervisão.';$private=app(VoiceCallingConfig::class)->read();$limit=min($p['voice_daily_limit'],$private['daily_limit']??50);$n=DB::table('voice_outbound_calls')->where('workspace_id',$w)->where('created_at','>=',now()->startOfDay())->count()+DB::table('voice_inbound_calls')->where('workspace_id',$w)->where('created_at','>=',now()->startOfDay())->count();return $n>=$limit?'Limite diário compartilhado de chamadas atingido.':null;}
 public function messages(int $w):void{$p=$this->get($w);abort_if($p['paused'],409,'Novos envios estão pausados pela supervisão.');abort_if(DB::table('wa_messages')->where('workspace_id',$w)->where('direction','outbound')->where('created_at','>=',now()->startOfDay())->count()>=$p['whatsapp_daily_limit'],429,'Limite diário compartilhado de WhatsApp atingido.');}
}
