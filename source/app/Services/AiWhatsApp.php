<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class AiWhatsApp {
 public function reserve(object $message,string $conversation):bool {
  $binding=DB::table('ai_whatsapp_bindings')->where('workspace_id',$message->workspace_id)->where('sender_id',$message->sender_id)->where('enabled',true)->first();
  if(!$binding||!$binding->enabled_at||$message->created_at<$binding->enabled_at)return false;
  $c=DB::table('wa_conversations')->find($conversation);$a=DB::table('ai_agents')->where('workspace_id',$message->workspace_id)->where('id',$binding->agent_id)->where('paused',false)->whereNotNull('published_version')->first();
  if(!$a||!$c||$c->assigned_user_id||$c->status!=='open'||!trim($message->body??'')||DB::table('ai_whatsapp_jobs')->where('conversation_id',$conversation)->whereIn('status',['handoff','stop','failed','unknown'])->exists())return false;
  if(!app(AiProvider::class)->connection($message->workspace_id)['text_enabled'])return false;
  DB::table('ai_whatsapp_jobs')->insertOrIgnore(['message_id'=>$message->id,'workspace_id'=>$message->workspace_id,'conversation_id'=>$conversation,'agent_id'=>$binding->agent_id,'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
  DB::afterCommit(fn()=>\App\Jobs\HandleAiWhatsApp::dispatch($message->id));
  return true;
 }
 public function owns(string $conversation):bool { $c=DB::table('wa_conversations')->find($conversation);if(!$c||$c->assigned_user_id)return false;$b=DB::table('ai_whatsapp_bindings as b')->join('ai_agents as a','a.id','=','b.agent_id')->where('b.sender_id',$c->sender_id)->where('b.enabled',true)->where('a.paused',false)->exists();if(!$b||!app(AiProvider::class)->connection($c->workspace_id)['text_enabled'])return false;return DB::table('ai_whatsapp_jobs')->where('conversation_id',$conversation)->whereIn('status',['pending','processing','sent'])->exists()&&!DB::table('ai_whatsapp_jobs')->where('conversation_id',$conversation)->whereIn('status',['handoff','stop','failed','unknown'])->exists();}
 private function eligible(object $job):object {
  $c=DB::table('wa_conversations')->where('workspace_id',$job->workspace_id)->where('id',$job->conversation_id)->firstOrFail();
  abort_unless($c->status==='open'&&!$c->assigned_user_id&&$c->last_inbound_at&&now()->subHours(24)->lt($c->last_inbound_at),409,'A conversa está com a equipe, encerrada ou fora da janela de resposta.');
  abort_unless(DB::table('ai_whatsapp_bindings')->where('workspace_id',$job->workspace_id)->where('sender_id',$c->sender_id)->where('agent_id',$job->agent_id)->where('enabled',true)->exists(),409,'Canal de IA desativado.');
  app(AiAgents::class)->published($job->workspace_id,$job->agent_id);app(AiProvider::class)->textReady($job->workspace_id);abort_if(app(OperationPolicy::class)->get($job->workspace_id)['paused'],409,'Operação pausada.');
  $contact=$c->contact_id?DB::table('voice_contacts')->where('workspace_id',$job->workspace_id)->where('id',$c->contact_id)->first():null;abort_if($contact?->suppressed_at,409,'Contato descadastrado.');
  return $c;
 }
 public function handoff(object $job,string $status,string $reason):void {
  DB::transaction(function()use($job,$status,$reason){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();DB::table('ai_whatsapp_jobs')->where('message_id',$job->message_id)->update(['status'=>$status,'reason'=>mb_substr($reason,0,250),'updated_at'=>now()]);$a=DB::table('ai_agents')->where('id',$job->agent_id)->first();$cfg=$a?json_decode(DB::table('ai_agent_versions')->where('agent_id',$a->id)->where('version',$a->published_version)->value('config')??'{}',true):[];
   if(!empty($cfg['handoff_queue_id']))DB::table('wa_conversations')->where('id',$job->conversation_id)->whereNull('assigned_user_id')->update(['queue_id'=>$cfg['handoff_queue_id'],'revision'=>DB::raw('revision+1'),'updated_at'=>now()]);
   app(VoiceLab::class)->audit($job->workspace_id,null,'ai.whatsapp.'.$status,$job->conversation_id,['message_id'=>$job->message_id,'reason'=>mb_substr($reason,0,250)]);
  });app(ConversationInbox::class)->distribute($job->conversation_id);
 }
 public function run(string $id):void {
  $job=DB::transaction(function()use($id){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$j=DB::table('ai_whatsapp_jobs')->where('message_id',$id)->first();if(!$j||$j->status!=='pending')return null;if(DB::table('ai_whatsapp_jobs')->where('conversation_id',$j->conversation_id)->where('status','processing')->exists())return null;DB::table('ai_whatsapp_jobs')->where('message_id',$id)->update(['status'=>'processing','updated_at'=>now()]);return $j;});if(!$job)return;
  try{
   $c=$this->eligible($job);$m=DB::table('wa_messages')->find($id);$s=app(AiAgents::class);$old=DB::table('ai_whatsapp_jobs')->where('conversation_id',$c->id)->where('agent_id',$job->agent_id)->whereNotNull('session_id')->orderByDesc('created_at')->first();$session=$old?$s->session($job->workspace_id,$old->session_id):$s->start($job->workspace_id,0,$job->agent_id,'premium','whatsapp',true);
   DB::table('ai_whatsapp_jobs')->where('message_id',$id)->update(['session_id'=>$session->id]);$t=$s->turn($job->workspace_id,$session->id,$id,mb_substr($m->body,0,2000));
   if($t->action==='stop'){
    DB::transaction(function()use($c,$job){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();if($c->contact_id){DB::table('voice_contacts')->where('workspace_id',$job->workspace_id)->where('id',$c->contact_id)->update(['suppressed_at'=>now(),'consent'=>false,'updated_at'=>now()]);DB::table('voice_followups')->where('workspace_id',$job->workspace_id)->where('contact_id',$c->contact_id)->whereIn('status',['pending','blocked'])->update(['status'=>'cancelled','reason'=>'Pedido de interrupção registrado pela IA.','updated_at'=>now()]);DB::table('voice_actions')->where('workspace_id',$job->workspace_id)->where('contact_id',$c->contact_id)->where('status','pending')->update(['status'=>'cancelled','reason'=>'Pedido de interrupção.','updated_at'=>now()]);}});$this->handoff($job,'stop','Cliente pediu interrupção. Nenhuma nova mensagem enviada.');return;
   }
   if($t->action==='handoff'){$this->handoff($job,'handoff',$s->session($job->workspace_id,$session->id)->summary??'Precisa de atendimento humano.');return;}
   $this->eligible($job);$this->send($job,$c,$t->output);DB::table('ai_whatsapp_jobs')->where('message_id',$id)->update(['status'=>'sent','updated_at'=>now()]);
  }catch(\Throwable $e){$this->handoff($job,'failed','A IA não concluiu o atendimento. A equipe deve conferir a conversa.');}
 }
 private function send(object $job,object $c,string $body):void {
  $w=$job->workspace_id;$sender=DB::table('wa_senders')->where('workspace_id',$w)->where('id',$c->sender_id)->firstOrFail();$cfg=$sender->provider==='qr'?app(WhatsAppQr::class)->config():app(TwilioWhatsAppConnection::class)->require();
  if($sender->provider==='qr')abort_unless(app(WhatsAppQr::class)->session($w,$sender->id)['verified'],422,'Remetente desconectado.');else abort_unless(app(WhatsAppMessages::class)->syncSender($w,$sender->id)->status==='ONLINE',422,'Remetente desconectado.');
  $mid=DB::transaction(function()use($w,$job,$c,$sender,$cfg,$body){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$this->eligible($job);app(OperationPolicy::class)->messages($w);abort_unless(in_array($c->phone,$cfg['allowed_recipients']??[],true),422,'Destino fora da lista de homologação do canal.');abort_if(DB::table('wa_messages')->where('provider',$sender->provider)->where('direction','outbound')->where('created_at','>=',now()->startOfDay())->count()>=($cfg['daily_limit']??10),429,'Limite diário do canal.');$key='ai:'.$job->message_id;abort_if(DB::table('wa_messages')->where('workspace_id',$w)->where('idempotency_key',$key)->exists(),409,'Resposta já reservada.');$mid=(string)Str::uuid();DB::table('wa_messages')->insert(['id'=>$mid,'workspace_id'=>$w,'sender_id'=>$sender->id,'contact_id'=>$c->contact_id,'conversation_id'=>$c->id,'provider'=>$sender->provider,'direction'=>'outbound','idempotency_key'=>$key,'request_hash'=>hash('sha256',$body),'account_sid'=>$sender->provider==='qr'?'qr:'.$sender->id:$cfg['account_sid'],'from_number'=>$sender->number,'to_number'=>$c->phone,'status'=>'sending','body'=>$body,'consent_evidence'=>'Resposta automática ao inbound '.$job->message_id.' pelo agente IA '.$job->agent_id,'created_at'=>now(),'updated_at'=>now()]);return $mid;});
  try{if($sender->provider==='qr'){$result=app(WhatsAppQr::class)->request('POST','/sessions/'.$sender->id.'/messages',['id'=>$mid,'number'=>$sender->number,'to'=>$c->phone,'text'=>$body]);app(WhatsAppQr::class)->apply($mid,$result);}else{$result=app(TwilioWhatsAppApi::class)->request('POST','https://api.twilio.com/2010-04-01/Accounts/'.$cfg['account_sid'].'/Messages.json',['From'=>'whatsapp:'.$sender->number,'To'=>'whatsapp:'.$c->phone,'Body'=>$body,'StatusCallback'=>TwilioWhatsAppConnection::base().'/status/'.$mid],true);abort_unless(($result['account_sid']??'')===$cfg['account_sid']&&preg_match('/^(?:SM|MM)[a-fA-F0-9]{32}$/D',$result['sid']??''),502);app(WhatsAppMessages::class)->apply($mid,$result['sid'],$result['status']??'accepted',null);}
   DB::table('wa_conversations')->where('id',$c->id)->update(['last_message_at'=>now(),'updated_at'=>now()]);app(VoiceLab::class)->audit($w,null,'ai.whatsapp.reply',$c->id,['message_id'=>$mid,'agent_id'=>$job->agent_id]);
  }catch(\Throwable $e){DB::table('wa_messages')->where('id',$mid)->where('status','sending')->update(['status'=>'unknown','updated_at'=>now()]);throw $e;}
 }
 public function tick():void {
  foreach(DB::table('ai_whatsapp_jobs')->where('status','processing')->where('updated_at','<',now()->subMinutes(3))->limit(20)->get()as $j)$this->handoff($j,'unknown','Processamento interrompido; conferir antes de retomar.');
  foreach(DB::table('ai_whatsapp_jobs')->where('status','pending')->orderBy('created_at')->limit(10)->pluck('message_id')as $id)$this->run($id);
 }
}
