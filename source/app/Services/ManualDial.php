<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class ManualDial {
 public function reserve(int $w,int $u,array $d):array {return DB::transaction(function()use($w,$u,$d){
  DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();app(VoiceCalling::class)->expire();app(VoiceLiveQueue::class)->settle();
  $number=VoiceLab::phone($d['number']);$q=DB::table('voice_live_queues')->where('workspace_id',$w)->where('id',$d['queue_id'])->firstOrFail();
  abort_unless(in_array($u,json_decode($q->agent_ids,true),true)&&$q->direction!=='inbound'&&$q->manual_enabled,403,'Esta fila não permite discagem manual para seu usuário.');
  $old=DB::table('voice_live_reservations')->where('workspace_id',$w)->where('idempotency_key',$d['idempotency_key'])->first();
  if($old){abort_unless($old->kind==='manual'&&$old->queue_id===$q->id&&$old->user_id===$u&&DB::table('voice_contacts')->where('id',$old->contact_id)->value('phone')===$number,409,'Identificador já utilizado em outra reserva.');return ['reservation'=>$old];}
  abort_unless($q->status==='running'&&AgentAvailability::available($w,$u,$q->id),409,'Fique online nesta fila e peça ao administrador para habilitar a saída.');
  abort_unless(DB::table('users')->where('id',$u)->where('voice_workspace_id',$w)->where('voice_enabled',true)->exists(),403);
  abort_if(app(VoiceAgentCapacity::class)->busy($w,$u),409,'Conclua sua chamada, reserva ou tabulação antes de discar.');
  $presence=DB::table('voice_agent_presence')->where('user_id',$u)->first();abort_if($presence->available_after&&now()->lt($presence->available_after),409,'Aguarde o pós-atendimento.');
  $cfg=app(VoiceCallingConfig::class);abort_unless($cfg->status($q->calling_method)['ready'],503,'A telefonia desta fila precisa ser configurada.');abort_unless(in_array($number,$cfg->read()['allowed_recipients']??[],true),422,'Número fora dos destinos autorizados pelo administrador.');
  $contact=DB::table('voice_contacts')->where('workspace_id',$w)->where('phone',$number)->first();abort_unless($contact&&$contact->consent&&$contact->consent_evidence&&!$contact->suppressed_at,422,'Cadastre este contato com autorização antes de ligar. Contatos que pediram interrupção permanecem bloqueados.');
  $limit=app(OperationPolicy::class)->voiceReason($w)??app(VoiceEligibility::class)->globalReason($w,$contact->id);abort_if($limit,422,$limit);
  abort_if(DB::table('voice_audio_sessions')->whereIn('status',['pending','connecting','active'])->where('expires_at','>',now())->exists()||DB::table('voice_outbound_calls')->whereNull('capacity_released_at')->exists()||DB::table('voice_inbound_calls')->whereNull('capacity_released_at')->exists(),409,'Há atendimento ou diagnóstico de áudio em andamento.');
  abort_if(DB::table('voice_live_reservations')->where('workspace_id',$w)->where('contact_id',$contact->id)->whereIn('status',VoiceLiveQueue::ACTIVE)->exists(),409,'Contato reservado por outro atendente.');
  $id=(string)Str::uuid();DB::table('voice_live_reservations')->insert(['id'=>$id,'workspace_id'=>$w,'queue_id'=>$q->id,'campaign_id'=>null,'kind'=>'manual','contact_id'=>$contact->id,'user_id'=>$u,'idempotency_key'=>$d['idempotency_key'],'expires_at'=>now()->addMinutes(3),'created_at'=>now(),'updated_at'=>now()]);app(VoiceLab::class)->audit($w,$u,'live_queue.manual_reserved',$id);return ['reservation'=>DB::table('voice_live_reservations')->find($id)];
 });}
}
