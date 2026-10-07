<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class ManualDial {
 /** Numbers are advertised only through queues assigned to this agent and a ready connection. */
 public function origins(int $w,int $u):array {
  $groups=[];$cfg=app(VoiceCallingConfig::class);
  foreach(DB::table('voice_live_queues')->where('workspace_id',$w)->where('direction','!=','inbound')->where('manual_enabled',true)->where('channels_configured',true)->orderBy('id')->get()as $q){
   if(!in_array($u,json_decode($q->agent_ids,true),true)||!$cfg->status($q->calling_method)['ready'])continue;
   try{$origin=app(QueueChannels::class)->origin($q,$cfg->connection($q->calling_method)??[]);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){continue;}
   $groups[$origin]??=['number'=>$origin,'routes'=>[]];
   $groups[$origin]['routes'][]=['queue_id'=>$q->id,'method'=>$q->calling_method,'allow_landline'=>(bool)$q->allow_landline,'allow_mobile'=>(bool)$q->allow_mobile];
  }
  return array_values($groups);
 }
 private function resolve(int $w,int $u,array $d,string $destination):int {
  $wrapup=app(AgentWrapup::class)->state($w,$u);abort_if($wrapup,409,'Pós-atendimento em andamento. Aguarde '.($wrapup['remaining_seconds']??0).' segundos.');
  $origin=collect($this->origins($w,$u))->firstWhere('number',$d['origin_number']);
  abort_unless($origin,422,'Este número de saída não está mais habilitado para você. Atualize os números disponíveis.');
  foreach($origin['routes']as $route){
   $q=DB::table('voice_live_queues')->find($route['queue_id']);
   if(QueueOperationSettings::open($q)&&QueueDestinations::reason($q,$destination)===null)return $q->id;
  }
  abort(409,'Nenhuma fila vinculada permite ligar para este destino com o número escolhido. Confira os horários e as permissões de destino.');
 }
 public function reserve(int $w,int $u,array $d):array {return DB::transaction(function()use($w,$u,$d){
  DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();app(VoiceCalling::class)->expire();app(VoiceLiveQueue::class)->settle();
  $number=VoiceLab::phone($d['number']);if(!empty($d['origin_number']))$d['queue_id']=$this->resolve($w,$u,$d,$number);$q=DB::table('voice_live_queues')->where('workspace_id',$w)->where('id',$d['queue_id'])->firstOrFail();
  abort_unless(in_array($u,json_decode($q->agent_ids,true),true)&&$q->direction!=='inbound'&&$q->manual_enabled,403,'Esta fila não permite discagem manual para seu usuário.');
  $old=DB::table('voice_live_reservations')->where('workspace_id',$w)->where('idempotency_key',$d['idempotency_key'])->first();
  if($old){abort_unless($old->kind==='manual'&&$old->queue_id===$q->id&&$old->user_id===$u&&DB::table('voice_contacts')->where('id',$old->contact_id)->value('phone')===$number,409,'Identificador já utilizado em outra reserva.');return ['reservation'=>$old];}
  abort_unless(DB::table('users')->where('id',$u)->where('voice_workspace_id',$w)->where('voice_enabled',true)->exists(),403);
  abort_if(app(VoiceAgentCapacity::class)->busy($w,$u),409,'Conclua sua chamada, reserva ou tabulação antes de discar.');
  $presence=DB::table('voice_agent_presence')->where('user_id',$u)->first();abort_if($presence?->available_after&&now()->lt($presence->available_after),409,'Aguarde o pós-atendimento.');
  $cfg=app(VoiceCallingConfig::class);abort_unless($cfg->status($q->calling_method)['ready'],503,'A telefonia desta fila precisa ser configurada.');abort_unless(in_array($number,$cfg->read()['allowed_recipients']??[],true),422,'Número fora dos destinos autorizados pelo administrador.');
  QueueOperationSettings::check($q);QueueDestinations::check($q,$number);
  $contact=DB::table('voice_contacts')->where('workspace_id',$w)->where('phone',$number)->first();abort_unless($contact&&$contact->consent&&$contact->consent_evidence&&!$contact->suppressed_at,422,'Cadastre este contato com autorização antes de ligar. Contatos que pediram interrupção permanecem bloqueados.');
  $limit=app(OperationPolicy::class)->voiceReason($w)??app(VoiceEligibility::class)->globalReason($w,$contact->id);abort_if($limit,422,$limit);
  abort_if(DB::table('voice_audio_sessions')->whereIn('status',['pending','connecting','active'])->where('expires_at','>',now())->exists()||app(VoiceCapacity::class)->full(),409,'Há atendimento ou diagnóstico de áudio em andamento.');
  abort_if(DB::table('voice_live_reservations')->where('workspace_id',$w)->where('contact_id',$contact->id)->whereIn('status',VoiceLiveQueue::ACTIVE)->exists(),409,'Contato reservado por outro atendente.');
  $this->bindSession($w,$u,$d['session_id']??null);
  $id=(string)Str::uuid();DB::table('voice_live_reservations')->insert(['id'=>$id,'workspace_id'=>$w,'queue_id'=>$q->id,'campaign_id'=>null,'kind'=>'manual','contact_id'=>$contact->id,'user_id'=>$u,'idempotency_key'=>$d['idempotency_key'],'expires_at'=>now()->addMinutes(3),'created_at'=>now(),'updated_at'=>now()]);app(VoiceLab::class)->audit($w,$u,'live_queue.manual_reserved',$id);return ['reservation'=>DB::table('voice_live_reservations')->find($id)];
 });}
 /** Manual calls require permission and capacity, never automatic-distribution presence. */
 public static function permitted(int $w,int $u):bool {
  return DB::table('users')->where('id',$u)->where('voice_workspace_id',$w)->where('voice_enabled',true)->exists()&&!app(AgentWrapup::class)->state($w,$u);
 }
 private function bindSession(int $w,int $u,?string $session):void {
  $p=DB::table('voice_agent_presence')->where('user_id',$u)->first();
  abort_if($p&&(int)$p->workspace_id!==$w,403);
  if($session&&$p?->session_id&&$p->session_id!==$session&&$p->status!=='offline'&&$p->last_seen_at&&\Carbon\CarbonImmutable::parse($p->last_seen_at)->gt(now()->subSeconds(90)))abort(409,'Sua disponibilidade está em outra aba. Use o discador daquela conexão ou fique offline nela primeiro.');
  // Keep status, selected queues and heartbeat untouched: dialing cannot make an agent online.
  if($p){if($session)DB::table('voice_agent_presence')->where('user_id',$u)->update(['session_id'=>$session]);}
  else DB::table('voice_agent_presence')->insert(['user_id'=>$u,'workspace_id'=>$w,'status'=>'offline','session_id'=>$session??(string)Str::uuid(),'queue_ids'=>'[]','created_at'=>now(),'updated_at'=>now()]);
 }

}
