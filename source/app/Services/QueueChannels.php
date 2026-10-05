<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class QueueChannels {
 public function catalog(int $w):array {
  $numbers=[];
  foreach(['programmable_voice','sip_trunk']as $method){
   $c=app(VoiceCallingConfig::class)->connection($method);
   if(!empty($c['caller_id']))$numbers[]=['number'=>$c['caller_id'],'method'=>$method];
   if(!empty($c['account_sid']))foreach(DB::table('voice_origins')->where('workspace_id',$w)->where('account_sid',$c['account_sid'])->where('enabled',true)->where('verified_at','>=',now()->subDay())->pluck('number')as $number)$numbers[]=['number'=>$number,'method'=>$method];
  }
  return ['incoming_numbers'=>app(InboundVoice::class)->numbers($w,app(TwilioVoiceConnection::class)->read()),'voice_numbers'=>collect($numbers)->unique(fn($n)=>$n['method'].':'.$n['number'])->values()->all(),'senders'=>DB::table('wa_senders')->where('workspace_id',$w)->get(['id','label','number','provider','status'])];
 }
 public function apply(?object $q,array $s):array {
  if(!$q||!$q->channels_configured)return $s;
  $sender=$q->whatsapp_sender_id?DB::table('wa_senders')->where('workspace_id',$q->workspace_id)->where('id',$q->whatsapp_sender_id)->first():null;
  return array_replace($s,['business_number'=>$q->voice_number,'whatsapp_sender_id'=>$sender?->id,'whatsapp_number'=>$q->number_mode==='single'?$q->voice_number:$sender?->number,'number_mode'=>$q->number_mode,'mode'=>$q->mode]);
 }
 public function origin(object $q,array $connection):string {
  abort_unless($q->voice_number,422,'Selecione o número de voz em Filas de atendimento.');
  $valid=$q->voice_number===($connection['caller_id']??null)||DB::table('voice_origins')->where('workspace_id',$q->workspace_id)->where('account_sid',$connection['account_sid']??'')->where('number',$q->voice_number)->where('enabled',true)->where('verified_at','>=',now()->subDay())->exists();
  abort_unless($valid,422,'O número da fila não está autorizado nesta conexão de voz. Atualize o catálogo ou selecione outra origem.');
  return $q->voice_number;
 }
 public function validate(object $q):void {
  if(!$q->channels_configured)return;
  if($q->direction!=='inbound')$this->origin($q,app(VoiceCallingConfig::class)->connection($q->calling_method)??[]);
  $sender=$q->whatsapp_sender_id?DB::table('wa_senders')->where('workspace_id',$q->workspace_id)->where('id',$q->whatsapp_sender_id)->first():null;
  abort_if($q->whatsapp_sender_id&&!$sender,422,'Selecione um remetente WhatsApp deste workspace.');
  abort_if($sender&&$q->number_mode==='single'&&$sender->number!==$q->voice_number,422,'No modo número único, o WhatsApp deve corresponder à origem de voz da fila.');
 }
 public function idle(object $q):void {
  abort_unless($q->status==='paused',409,'Pause as cadências desta fila antes de alterar os vínculos ou números.');
  abort_if(DB::table('voice_live_reservations')->where('queue_id',$q->id)->whereIn('status',VoiceLiveQueue::ACTIVE)->exists()||DB::table('voice_outbound_calls')->where('queue_id',$q->id)->whereNull('capacity_released_at')->exists()||DB::table('voice_inbound_calls')->where('queue_id',$q->id)->where(fn($b)=>$b->whereNull('capacity_released_at')->orWhere('status','tabulation'))->exists(),409,'Conclua os atendimentos desta fila antes de alterar os vínculos ou números.');
 }
 public function bind(int $w,int $campaign,?int $queue):void {
  $old=QueueRouting::forCampaign($w,$campaign)->get();if($old->count()===1&&$old->first()->id===$queue)return;
  $next=$queue?DB::table('voice_live_queues')->where('workspace_id',$w)->where('id',$queue)->where('direction','!=','inbound')->firstOrFail():null;
  foreach($old as $q)$this->idle($q);if($next)$this->idle($next);
  foreach($old as $q){DB::table('voice_queue_campaigns')->where('queue_id',$q->id)->where('campaign_id',$campaign)->delete();$remaining=array_values(array_diff(QueueRouting::campaigns($q),[$campaign]));DB::table('voice_live_queues')->where('id',$q->id)->update(['campaign_id'=>$remaining[0]??null,'revision'=>$q->revision+1,'updated_at'=>now()]);}
  if($next){DB::table('voice_queue_campaigns')->insertOrIgnore(['queue_id'=>$next->id,'campaign_id'=>$campaign]);DB::table('voice_live_queues')->where('id',$next->id)->update(['campaign_id'=>$next->campaign_id??$campaign,'revision'=>$next->revision+1,'updated_at'=>now()]);}
 }
 public function incoming(object $q,?string $number):void {
  if(!$number)return;
  abort_unless($q->direction!=='outbound'&&in_array($number,app(InboundVoice::class)->numbers($q->workspace_id,app(TwilioVoiceConnection::class)->read()),true),422,'Selecione um número próprio autorizado para uma fila receptiva ou mista.');
  $old=DB::table('voice_inbound_routes')->where('number',$number)->first();
  abort_if($old&&($old->workspace_id!==$q->workspace_id||$old->queue_id!==$q->id),409,'Este número já está vinculado a outra fila. Altere a rota em Receptivo.');
  if(!$old)DB::table('voice_inbound_routes')->insert(['workspace_id'=>$q->workspace_id,'number'=>$number,'queue_id'=>$q->id,'enabled'=>false,'ring_seconds'=>20,'wait_seconds'=>120,'revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
 }
 public function propagate(object $q):void {
  if(!$q->channels_configured)return;
  foreach(QueueRouting::campaigns($q)as $id){
   $c=DB::table('voice_campaigns')->where('workspace_id',$q->workspace_id)->find($id);$old=json_decode($c->settings,true);$s=$this->apply($q,$old);if($s===$old)continue;
   abort_unless(in_array($c->status,['paused','draft']),409,'Pause as cadências vinculadas antes de alterar os canais da fila.');
   abort_if(DB::table('voice_followups')->where('campaign_id',$id)->where('status','dispatching')->exists()||DB::table('voice_outbound_calls')->where('campaign_id',$id)->whereNull('capacity_released_at')->exists(),409,'Aguarde a conclusão dos envios e chamadas desta cadência.');
   // A provider change needs templates compatible with that provider, chosen in the cadence.
   if($s['whatsapp_enabled']){
    app(WhatsAppNumbers::class)->normalize($q->workspace_id,$s);
    if(($s['whatsapp_delivery']??'')==='automatic'){
     $sender=DB::table('wa_senders')->where('workspace_id',$q->workspace_id)->find($s['whatsapp_sender_id']??0);abort_unless($sender,422,'As cadências usam WhatsApp. Selecione um remetente na fila ou desative a mensagem nas cadências.');
     if($sender->provider==='qr')abort_unless(!empty($s['whatsapp_qr_template_id'])||trim($s['whatsapp_text']??'')!=='',422,'Configure a mensagem QR nas cadências antes de trocar o canal.');
     else abort_unless(DB::table('wa_templates')->where('workspace_id',$q->workspace_id)->where('id',$s['whatsapp_real_template_id']??'')->exists(),422,'Configure o template Twilio nas cadências antes de trocar o canal.');
    }
   }
   $changed=VoiceFollowups::ruleChanged($old,$s);DB::table('voice_campaigns')->where('id',$id)->update(['settings'=>json_encode($s),'revision'=>$c->revision+1,'followup_revision'=>$c->followup_revision+($changed?1:0),'updated_at'=>now()]);
   if($changed)DB::table('voice_followups')->where('campaign_id',$id)->whereIn('status',['pending','blocked'])->update(['status'=>'cancelled','reason'=>'Canais da fila alterados.','updated_at'=>now()]);
  }
 }
}
