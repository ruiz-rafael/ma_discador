<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class ConversationInbox {
 public function record(string $id,array $attribution=[]):?string {
  return DB::transaction(function()use($id,$attribution){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$m=DB::table('wa_messages')->find($id);if(!$m)return null;if($m->conversation_id)return $m->conversation_id;
   $phone=$m->direction==='inbound'?$m->from_number:$m->to_number;
   $c=DB::table('wa_conversations')->where('workspace_id',$m->workspace_id)->where('sender_id',$m->sender_id)->where('phone',$phone)->first();
   if(!$c){$cid=(string)Str::uuid();$route=DB::table('wa_inbox_routes')->where('workspace_id',$m->workspace_id)->where('sender_id',$m->sender_id)->first();DB::table('wa_conversations')->insert(['id'=>$cid,'workspace_id'=>$m->workspace_id,'sender_id'=>$m->sender_id,'contact_id'=>$m->contact_id,'phone'=>$phone,'queue_id'=>$route?->queue_id,'status'=>'open','created_at'=>$m->created_at,'updated_at'=>now()]);$c=DB::table('wa_conversations')->find($cid);}
   $v=['updated_at'=>now(),'contact_id'=>$m->contact_id??$c->contact_id];if(!$c->queue_id&&!$c->assigned_user_id){$route=DB::table('wa_inbox_routes')->where('workspace_id',$m->workspace_id)->where('sender_id',$m->sender_id)->first();if($route)$v['queue_id']=$route->queue_id;}if(!$c->last_message_at || $m->created_at>$c->last_message_at)$v['last_message_at']=$m->created_at;
   if($m->direction==='inbound'&&(!$c->last_inbound_at||$m->created_at>$c->last_inbound_at)){$v['last_inbound_at']=$m->created_at;$v['status']='open';if($c->status==='closed')$v['revision']=$c->revision+1;}
   if($attribution)$v['attribution']=json_encode(array_intersect_key($attribution,array_flip(['source','source_id','source_url','headline','ctwa_clid','ad_id','campaign_id','form_id','lead_id'])));
   DB::table('wa_conversations')->where('id',$c->id)->update($v);DB::table('wa_messages')->where('id',$id)->update(['conversation_id'=>$c->id]);if($m->direction==='inbound'){$this->distribute($c->id);app(IntegrationEvents::class)->emit($m->workspace_id,'message.received',$id,'message:'.$id,['conversation_id'=>$c->id,'button_id'=>$m->button_id,'button_label'=>$m->button_label,'source_created_at'=>$m->created_at]);}return $c->id;
  });
 }
 public function distribute(string $id):void {
  DB::transaction(function()use($id){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$this->distributeLocked($id);});
 }
 private function distributeLocked(string $id):void {
  $c=DB::table('wa_conversations')->find($id);if(!$c||$c->assigned_user_id||!$c->queue_id||$c->status!=='open')return;
  $route=DB::table('wa_inbox_routes')->where('sender_id',$c->sender_id)->where('enabled',true)->first();if(!$route)return;
  $q=DB::table('voice_live_queues')->where('workspace_id',$c->workspace_id)->where('id',$c->queue_id)->first();if(!$q)return;
  $agents=DB::table('users as u')->join('voice_agent_presence as p','p.user_id','=','u.id')->where('u.voice_workspace_id',$c->workspace_id)->where('u.voice_enabled',true)->whereIn('u.id',json_decode($q->agent_ids,true))->where('p.status','available')->where('p.last_seen_at','>',now()->subSeconds(90))->get(['u.id']);
  $counts=DB::table('wa_conversations')->where('workspace_id',$c->workspace_id)->where('status','open')->selectRaw('assigned_user_id,count(*) as n')->groupBy('assigned_user_id')->pluck('n','assigned_user_id');
  $agents=$agents->filter(fn($a)=>AgentAvailability::available($c->workspace_id,$a->id,$c->queue_id));
  $agents=$agents->filter(fn($a)=>($counts[$a->id]??0)<$route->max_open)->sort(fn($a,$b)=>(($counts[$a->id]??0)<=>($counts[$b->id]??0))?:($a->id<=>$b->id));
  $agent=app(QueueDistribution::class)->order($q,$agents,'whatsapp')->first();
  if($agent){app(QueueDistribution::class)->record($q,$agent->id,'whatsapp');DB::table('wa_conversations')->where('id',$id)->update(['assigned_user_id'=>$agent->id,'revision'=>$c->revision+1,'updated_at'=>now()]);app(VoiceLab::class)->audit($c->workspace_id,null,'conversation.distributed',$id,['user_id'=>$agent->id]);}
 }
 public function visible(int $w,object $u):\Illuminate\Database\Query\Builder {
  $q=DB::table('wa_conversations')->where('workspace_id',$w);if(in_array($u->voice_role,['admin','supervisor'],true))return $q;
  $ids=DB::table('voice_live_queues')->where('workspace_id',$w)->get()->filter(fn($v)=>in_array($u->id,json_decode($v->agent_ids,true),true))->pluck('id');
  return $q->where(fn($b)=>$b->where('assigned_user_id',$u->id)->orWhere(fn($v)=>$v->whereNull('assigned_user_id')->whereIn('queue_id',$ids)));
 }
 public function assertSend(int $w,object $u,string $id):object {
  $c=$this->visible($w,$u)->where('id',$id)->firstOrFail();abort_if(app(OperationPolicy::class)->get($w)['paused'],409,'Envios pausados pela supervisão.');abort_unless($u->voice_enabled && $c->status==='open'&&$c->assigned_user_id===$u->id,409,'Assuma a conversa antes de responder.');
  abort_unless($c->last_inbound_at && \Carbon\CarbonImmutable::parse($c->last_inbound_at)->gt(now()->subHours(24)),422,'A janela de resposta terminou. Retome por um template aprovado e um fluxo autorizado.');
  $contact=$c->contact_id?DB::table('voice_contacts')->where('workspace_id',$w)->where('id',$c->contact_id)->first():null;abort_if($contact?->suppressed_at,422,'Contato pediu interrupção. O envio está bloqueado.');
  return $c;
 }
 public function send(int $w,object $u,string $id,array $d):object {
  $hash=hash('sha256',json_encode([$id,$u->id,$d['body']??null,$d['qr_template_id']??null]));
  $m=DB::transaction(function()use($w,$u,$id,$d,$hash){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$c=$this->assertSend($w,$u,$id);
   $old=DB::table('wa_messages')->where('workspace_id',$w)->where('idempotency_key',$d['idempotency_key'])->first();if($old){abort_unless($old->request_hash===$hash && $old->conversation_id===$id,409,'Identificador já usado com outro conteúdo.');return ['new'=>false,'row'=>$old];}
   app(OperationPolicy::class)->messages($w);
   $sender=DB::table('wa_senders')->where('workspace_id',$w)->where('id',$c->sender_id)->firstOrFail();$cfg=$sender->provider==='qr'?app(WhatsAppQr::class)->config():app(TwilioWhatsAppConnection::class)->require();abort_unless(in_array($c->phone,$cfg['allowed_recipients']??[],true),422,'Destino fora da lista de homologação deste canal.');
   abort_if(DB::table('wa_messages')->where('provider',$sender->provider)->where('direction','outbound')->where('created_at','>=',now()->startOfDay())->count()>=($cfg['daily_limit']??10),429,'Limite diário do canal atingido.');
   $body=$d['body']??'';$interactive=null;$template=$d['qr_template_id']??null;
   if($template){abort_unless($sender->provider==='qr'&&$c->contact_id&&($d['experimental_confirmed']??false),422,'Botões exigem contato identificado, canal QR e confirmação experimental.');$render=app(WhatsAppQrTemplates::class)->render($w,$template,$c->contact_id,DB::table('wa_messages')->where('workspace_id',$w)->where('conversation_id',$id)->whereNotNull('campaign_id')->orderByDesc('created_at')->value('campaign_id'));$body=$render['body'];$interactive=json_encode(['mode'=>'experimental_buttons','buttons'=>$render['buttons']]);}
   $mid=(string)Str::uuid();DB::table('wa_messages')->insert(['id'=>$mid,'workspace_id'=>$w,'sender_id'=>$sender->id,'contact_id'=>$c->contact_id,'conversation_id'=>$id,'user_id'=>$u->id,'provider'=>$sender->provider,'direction'=>'outbound','idempotency_key'=>$d['idempotency_key'],'request_hash'=>$hash,'account_sid'=>$sender->provider==='qr'?'qr:'.$sender->id:$cfg['account_sid'],'from_number'=>$sender->number,'to_number'=>$c->phone,'status'=>'sending','body'=>$body,'qr_template_id'=>$template,'interactive'=>$interactive,'consent_evidence'=>'Resposta manual à mensagem recebida em '.$c->last_inbound_at,'created_at'=>now(),'updated_at'=>now()]);DB::table('wa_conversations')->where('id',$id)->update(['last_message_at'=>now(),'updated_at'=>now()]);app(VoiceLab::class)->audit($w,$u->id,'conversation.reply_reserved',$id,['message_id'=>$mid]);return ['new'=>true,'row'=>DB::table('wa_messages')->find($mid)];
  });if(!$m['new'])return $m['row'];$m=$m['row'];
  try{
   if($m->provider==='qr'){$session=app(WhatsAppQr::class)->session($w,$m->sender_id);abort_unless($session['verified']&&(!$m->interactive||($session['capabilities']['experimental_buttons']??false)),422,'Conecte o remetente e confira a capacidade do canal.');}
   else {$sender=app(WhatsAppMessages::class)->syncSender($w,$m->sender_id);abort_unless($sender->status==='ONLINE',422,'Remetente não está online.');}
   $this->assertSend($w,$u,$id);
  }catch(\Throwable $e){DB::table('wa_messages')->where('id',$m->id)->update(['status'=>'cancelled','updated_at'=>now()]);throw $e;}
  try{if($m->provider==='qr'){$payload=['id'=>$m->id,'number'=>$m->from_number,'to'=>$m->to_number,'text'=>$m->body];if($m->interactive)$payload['interactive']=json_decode($m->interactive,true);$res=app(WhatsAppQr::class)->request('POST','/sessions/'.$m->sender_id.'/messages',$payload);app(WhatsAppQr::class)->apply($m->id,$res);}
   else {$res=app(TwilioWhatsAppApi::class)->request('POST','https://api.twilio.com/2010-04-01/Accounts/'.$m->account_sid.'/Messages.json',['From'=>'whatsapp:'.$m->from_number,'To'=>'whatsapp:'.$m->to_number,'Body'=>$m->body,'StatusCallback'=>TwilioWhatsAppConnection::BASE.'/status/'.$m->id],true);abort_unless(($res['account_sid']??'')===$m->account_sid&&preg_match('/^SM[a-fA-F0-9]{32}$/D',$res['sid']??''),502);app(WhatsAppMessages::class)->apply($m->id,$res['sid'],$res['status']??'accepted',null);}
  }catch(\Throwable $e){DB::table('wa_messages')->where('id',$m->id)->where('status','sending')->update(['status'=>str_starts_with($e->getMessage(),'twilio_rejected_')?'failed':'unknown','updated_at'=>now()]);}
  return DB::table('wa_messages')->find($m->id);
 }
}
