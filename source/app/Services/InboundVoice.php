<?php
namespace App\Services;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Twilio\TwiML\VoiceResponse;
use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VoiceGrant;
class InboundVoice {
 public const BASE=TwilioVoiceConnection::BASE.'/inbound';
 private function lock():void {DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();}
 public function numbers(int $w,?array $cfg):array {if(!$cfg)return [];return collect([$cfg['caller_id']])->merge(DB::table('voice_origins')->where('workspace_id',$w)->where('account_sid',$cfg['account_sid'])->where('kind','owned')->where('verified_at','>=',now()->subDay())->pluck('number'))->filter()->unique()->values()->all();}
 public function token(int $w,int $u,string $session):array {
  return DB::transaction(function()use($w,$u,$session){$this->lock();
   abort_unless(DB::table('users')->where('id',$u)->where('voice_workspace_id',$w)->where('voice_enabled',true)->exists(),403);
   abort_unless(DB::table('voice_live_queues')->where('workspace_id',$w)->whereIn('direction',['inbound','mixed'])->get()->contains(fn($q)=>in_array($u,json_decode($q->agent_ids,true),true)),403,'Você não está vinculado a uma fila receptiva.');
   $c=app(TwilioVoiceConnection::class)->read();abort_unless($w===1 && $c && $c['enabled'],422,'Habilite a conexão Twilio API.');
   $old=DB::table('voice_inbound_devices')->where('user_id',$u)->first();
   abort_if($old && $old->session_id!==$session && ($old->last_seen_at>now()->subSeconds(60)->toDateTimeString() || app(VoiceAgentCapacity::class)->inboundBusy($w,$u)),409,'A conexão anterior do receptivo ainda está registrada. Se acabou de recarregar, aguarde até 60 segundos e tente ficar online novamente. Se houver outra aba atendendo, use aquela aba.');
   $identity='ma_in_'.$w.'_'.$u.'_'.str_replace('-','',$session);
   DB::table('voice_inbound_devices')->updateOrInsert(['user_id'=>$u],['workspace_id'=>$w,'session_id'=>$session,'identity'=>$identity,'last_seen_at'=>now(),'ready'=>$old && $old->session_id===$session ? $old->ready:false]);
   $token=new AccessToken($c['account_sid'],$c['api_key'],$c['api_secret'],300,$identity);$grant=new VoiceGrant;$grant->setIncomingAllow(true);$token->addGrant($grant);
   return ['access_token'=>$token->toJWT(),'edge'=>$c['edge'],'expires_in'=>300];
  });
 }
 public function device(int $w,int $u,string $session,bool $ready):void {
  DB::transaction(function()use($w,$u,$session,$ready){$this->lock();
   $device=DB::table('voice_inbound_devices')->where('workspace_id',$w)->where('user_id',$u)->where('session_id',$session);
   if(!$ready){
    // An explicit release must not renew the old session's lease. Keep its identity
    // only while an offer, call or tabulation still belongs to this connection.
    if(app(VoiceAgentCapacity::class)->inboundBusy($w,$u))$device->update(['ready'=>false]);
    else $device->delete();
    return; // Repeated or late release from an old session is harmless.
   }
   abort_unless($device->update(['ready'=>true,'last_seen_at'=>now()]),409,'Reconecte o receptivo nesta aba.');
  });
 }
 public function receive(array $d):string {
  return DB::transaction(function()use($d){$this->lock();$xml=new VoiceResponse;
   $route=DB::table('voice_inbound_routes')->where('number',$d['To'])->where('enabled',true)->first();
   $queue=$route?DB::table('voice_live_queues')->where('workspace_id',$route->workspace_id)->find($route->queue_id):null;
   if(!$route||!QueueRouting::incoming($queue)||app(OperationPolicy::class)->voiceReason($route->workspace_id)){$xml->reject(['reason'=>'busy']);return (string)$xml;}
   if(DB::table('voice_inbound_calls')->where('call_sid',$d['CallSid'])->exists())return app(TwilioVoiceCalling::class)->hangup();
   // Shared admission across inbound and outbound; production default remains one.
   if(DB::table('voice_audio_sessions')->whereIn('status',['pending','connecting','active'])->where('expires_at','>',now())->exists() || app(VoiceCapacity::class)->full()){$xml->reject(['reason'=>'busy']);return (string)$xml;}
   $id=(string)Str::uuid();DB::table('voice_inbound_calls')->insert(['id'=>$id,'workspace_id'=>$route->workspace_id,'route_id'=>$route->id,'queue_id'=>$route->queue_id,'account_sid'=>$d['AccountSid'],'call_sid'=>$d['CallSid'],'from_number'=>$d['From'],'to_number'=>$d['To'],'created_at'=>now(),'updated_at'=>now()]);
   app(VoiceLab::class)->audit($route->workspace_id,null,'inbound.received',$id);
   return $this->dispatch($id,$d['CallSid']);
  });
 }
 private function candidates(object $call):\Illuminate\Support\Collection {
  $q=DB::table('voice_live_queues')->where('workspace_id',$call->workspace_id)->find($call->queue_id);
  if(!QueueRouting::incoming($q))return collect();
  return DB::table('users as u')->join('voice_agent_presence as p','p.user_id','=','u.id')->join('voice_inbound_devices as d','d.user_id','=','u.id')
   ->where('u.voice_workspace_id',$call->workspace_id)->where('u.voice_enabled',true)->whereIn('u.id',json_decode($q->agent_ids,true))
   ->where('p.workspace_id',$call->workspace_id)->where('p.status','available')->where('p.last_seen_at','>',now()->subSeconds(90))
   ->where(fn($b)=>$b->whereNull('p.available_after')->orWhere('p.available_after','<=',now()))
   ->where('d.workspace_id',$call->workspace_id)->where('d.ready',true)->where('d.last_seen_at','>',now()->subSeconds(45))->orderBy('p.updated_at')->orderBy('u.id')->get(['u.id','u.name','d.identity'])
   ->filter(fn($u)=>AgentAvailability::available($call->workspace_id,$u->id,$call->queue_id)&&!app(VoiceAgentCapacity::class)->busy($call->workspace_id,$u->id))->values();
 }
 public function dispatch(string $id,string $sid):string {
  return DB::transaction(function()use($id,$sid){$this->lock();$c=DB::table('voice_inbound_calls')->find($id);abort_unless($c && $c->call_sid===$sid,403);
   if($c->capacity_released_at || $c->status!=='waiting')return app(TwilioVoiceCalling::class)->hangup();
   $route=DB::table('voice_inbound_routes')->find($c->route_id);$xml=new VoiceResponse;
   $queue=DB::table('voice_live_queues')->where('workspace_id',$c->workspace_id)->find($c->queue_id);
   if(!QueueRouting::incoming($queue)||!$route->enabled || app(OperationPolicy::class)->get($c->workspace_id)['paused'] || now()->gte(\Carbon\CarbonImmutable::parse($c->created_at)->addSeconds($route->wait_seconds))){DB::table('voice_inbound_calls')->where('id',$id)->update(['status'=>'unavailable','updated_at'=>now()]);$xml->say('No momento não há atendentes disponíveis. Por favor, tente novamente mais tarde.',['language'=>'pt-BR']);$xml->hangup();return (string)$xml;}
   // Don't repeatedly ring a rejecting/unreachable agent during the same incoming call.
   $tried=DB::table('voice_inbound_offers')->where('call_id',$id)->pluck('user_id')->all();$agent=app(QueueDistribution::class)->order($queue,$this->candidates($c)->filter(fn($u)=>!in_array($u->id,$tried,true)),'inbound')->first();
   if(!$agent){$xml->say('Aguarde, estamos procurando um atendente.',['language'=>'pt-BR']);$xml->pause(['length'=>5]);$xml->redirect(self::BASE.'/wait/'.$id,['method'=>'POST']);return (string)$xml;}
   $offer=$this->offer($c,$agent);return $this->render($offer);
  });
 }
 private function offer(object $c,object $agent,bool $transfer=false):string {
  $id=(string)Str::uuid();DB::table('voice_inbound_offers')->insert(['id'=>$id,'call_id'=>$c->id,'user_id'=>$agent->id,'identity'=>$agent->identity,'status'=>$transfer?'transfer_pending':'offered','created_at'=>now(),'updated_at'=>now()]);
  app(QueueDistribution::class)->record(DB::table('voice_live_queues')->find($c->queue_id),$agent->id,'inbound');
  if(!$transfer)DB::table('voice_inbound_calls')->where('id',$c->id)->update(['status'=>'ringing','user_id'=>$agent->id,'updated_at'=>now()]);
  app(VoiceLab::class)->audit($c->workspace_id,$agent->id,$transfer?'inbound.transfer_reserved':'inbound.offered',$c->id,['offer_id'=>$id]);return $id;
 }
 private function render(string $offer):string {
  $o=DB::table('voice_inbound_offers')->find($offer);$c=DB::table('voice_inbound_calls')->find($o->call_id);$route=DB::table('voice_inbound_routes')->find($c->route_id);
  if($o->rendered)return app(TwilioVoiceCalling::class)->hangup();
  DB::table('voice_inbound_offers')->where('id',$offer)->update(['rendered'=>true,'updated_at'=>now()]);
  $xml=new VoiceResponse;$dial=$xml->dial(null,['answerOnBridge'=>true,'timeout'=>$route->ring_seconds,'timeLimit'=>min(1800,app(VoiceCallingConfig::class)->read()['max_seconds']??180),'record'=>'do-not-record','action'=>self::BASE.'/finish/'.$offer,'method'=>'POST']);
  $client=$dial->client(null,['statusCallback'=>self::BASE.'/offer/'.$offer,'statusCallbackMethod'=>'POST','statusCallbackEvent'=>'initiated ringing answered completed']);$client->identity($o->identity);$client->parameter(['name'=>'MAIncomingId','value'=>$c->id]);return (string)$xml;
 }
 public function offerStatus(string $id,array $d):void {
  DB::transaction(function()use($id,$d){$this->lock();$o=DB::table('voice_inbound_offers')->find($id);abort_unless($o,404);$c=DB::table('voice_inbound_calls')->find($o->call_id);
   abort_unless($c->account_sid===$d['AccountSid'] && $c->call_sid===$d['ParentCallSid'] && (!$o->child_sid||$o->child_sid===$d['CallSid']),403);
   if(in_array($d['CallStatus'],['in-progress','completed'],true)){
    $this->stopCadences($c);
    DB::table('voice_inbound_offers')->where('id',$id)->update(['answered_at'=>$o->answered_at??now(),'child_sid'=>$d['CallSid']]);
    $late=['answered_at'=>$c->answered_at??now()];if($c->capacity_released_at&&!$c->disposition_code&&$o->status!=='transferred'&&($c->user_id===$o->user_id||$c->user_id===null)){$late['status']='tabulation';$late['user_id']=$o->user_id;}DB::table('voice_inbound_calls')->where('id',$c->id)->update($late);if(isset($late['status']))app(AgentWrapup::class)->start(DB::table('voice_live_queues')->find($c->queue_id),$o->user_id,'inbound:'.$c->id,$c->ended_at);
   }
   if($c->capacity_released_at || !in_array($o->status,['offered','answered'],true))return;
   $v=['child_sid'=>$d['CallSid'],'updated_at'=>now()];if($d['CallStatus']==='in-progress'){$v+=['status'=>'answered','answered_at'=>$o->answered_at??now()];DB::table('voice_inbound_calls')->where('id',$c->id)->update(['status'=>'answered','answered_at'=>$c->answered_at??now(),'updated_at'=>now()]);}
   DB::table('voice_inbound_offers')->where('id',$id)->update($v);
  });
 }
 public function finish(string $id,array $d):string {
  return DB::transaction(function()use($id,$d){$this->lock();$o=DB::table('voice_inbound_offers')->find($id);abort_unless($o,404);$c=DB::table('voice_inbound_calls')->find($o->call_id);abort_unless($c->call_sid===$d['CallSid']&&$c->account_sid===$d['AccountSid'],403);
   if($d['DialCallStatus']==='completed'&&$o->status!=='transferred'){$this->stopCadences($c);DB::table('voice_inbound_offers')->where('id',$id)->update(['answered_at'=>$o->answered_at??now()]);DB::table('voice_inbound_calls')->where('id',$c->id)->update(['answered_at'=>$c->answered_at??now()]+($c->capacity_released_at&&!$c->disposition_code?['status'=>'tabulation','user_id'=>$o->user_id]:[]));}
   if($c->capacity_released_at&&$d['DialCallStatus']==='completed'&&$o->status!=='transferred'&&!$c->disposition_code)app(AgentWrapup::class)->start(DB::table('voice_live_queues')->find($c->queue_id),$o->user_id,'inbound:'.$c->id,$c->ended_at);
   if($c->capacity_released_at || !in_array($o->status,['offered','answered'],true))return app(TwilioVoiceCalling::class)->hangup();
   abort_if(!empty($d['DialCallSid']) && $o->child_sid && $o->child_sid!==$d['DialCallSid'],403);
   DB::table('voice_inbound_offers')->where('id',$id)->update(['status'=>$d['DialCallStatus'],'ended_at'=>now(),'updated_at'=>now()]);
   if($d['DialCallStatus']==='completed'||$o->answered_at){DB::table('voice_inbound_calls')->where('id',$c->id)->update(['answered_at'=>$c->answered_at??now(),'status'=>'ending','updated_at'=>now()]);return app(TwilioVoiceCalling::class)->hangup();}
   DB::table('voice_inbound_calls')->where('id',$c->id)->update(['status'=>'waiting','user_id'=>null,'updated_at'=>now()]);return $this->dispatch($c->id,$c->call_sid);
  });
 }
 public function ended(array $d):void {
  DB::transaction(function()use($d){$this->lock();$c=DB::table('voice_inbound_calls')->where('call_sid',$d['CallSid'])->where('account_sid',$d['AccountSid'])->first();if(!$c||$c->capacity_released_at)return;
   if(!in_array($d['CallStatus'],['completed','busy','failed','no-answer','canceled'],true))return;
   $owner=DB::table('voice_inbound_offers')->where('call_id',$c->id)->whereNotNull('answered_at')->orderByDesc('answered_at')->orderByDesc('created_at')->first();
   DB::table('voice_inbound_calls')->where('id',$c->id)->update(['user_id'=>$owner?->user_id??$c->user_id,'status'=>($c->answered_at||$owner)?'tabulation':($c->status==='unavailable'?'unavailable':'abandoned'),'bill_seconds'=>isset($d['CallDuration'])?(int)$d['CallDuration']:null,'ended_at'=>now(),'capacity_released_at'=>now(),'updated_at'=>now()]);
   DB::table('voice_inbound_offers')->where('call_id',$c->id)->whereIn('status',['offered','answered','transfer_pending','unknown'])->update(['status'=>'completed','ended_at'=>now(),'updated_at'=>now()]);
   if($c->answered_at||$owner)app(AgentWrapup::class)->start(DB::table('voice_live_queues')->find($c->queue_id),$owner?->user_id??$c->user_id,'inbound:'.$c->id,now()->toDateTimeString());
   app(VoiceLab::class)->audit($c->workspace_id,$c->user_id,'inbound.ended',$c->id);
  });
 }
 public function transfer(int $w,int $u,string $id,int $target):array {
  $offer=DB::transaction(function()use($w,$u,$id,$target){$this->lock();$c=DB::table('voice_inbound_calls')->where('workspace_id',$w)->where('user_id',$u)->find($id);abort_unless($c,404);abort_unless($c->status==='answered'&&!$c->capacity_released_at,409,'A chamada não está em conversa.');
   abort_if(DB::table('voice_inbound_offers')->where('call_id',$id)->where('status','transfer_pending')->exists(),409,'Uma transferência aguarda confirmação.');
   $agent=$this->candidates($c)->firstWhere('id',$target);abort_unless($agent,409,'Destino indisponível para receber a transferência.');return $this->offer($c,$agent,true);
  });
  $c=DB::table('voice_inbound_calls')->find($id);
  try{$res=$this->http($c)->post($this->callUrl($c),['Url'=>self::BASE.'/transfer/'.$offer,'Method'=>'POST']);if(!$res->successful()){if($res->clientError())DB::table('voice_inbound_offers')->where('id',$offer)->where('status','transfer_pending')->update(['status'=>'failed','ended_at'=>now(),'updated_at'=>now()]);abort(502,'A Twilio não confirmou a transferência. Consulte o estado antes de repetir.');}}catch(\Illuminate\Http\Client\ConnectionException){abort(502,'Transferência sem confirmação. As reservas foram preservadas para conciliação.');}
  return ['accepted'=>true,'offer_id'=>$offer];
 }
 public function transferDial(string $id,array $d):string {
  return DB::transaction(function()use($id,$d){$this->lock();$o=DB::table('voice_inbound_offers')->find($id);abort_unless($o,404);$c=DB::table('voice_inbound_calls')->find($o->call_id);abort_unless($c->call_sid===$d['CallSid']&&$c->account_sid===$d['AccountSid'],403);
   if($c->capacity_released_at||$o->status!=='transfer_pending')return app(TwilioVoiceCalling::class)->hangup();
   DB::table('voice_inbound_offers')->where('call_id',$c->id)->where('id','!=',$id)->whereIn('status',['offered','answered'])->update(['status'=>'transferred','ended_at'=>now(),'updated_at'=>now()]);
   DB::table('voice_inbound_offers')->where('id',$id)->update(['status'=>'offered','updated_at'=>now()]);DB::table('voice_inbound_calls')->where('id',$c->id)->update(['user_id'=>$o->user_id,'status'=>'ringing','updated_at'=>now()]);return $this->render($id);
  });
 }
 public function publishRoute(int $w,int $u,int $id):array {
  $route=DB::table('voice_inbound_routes')->where('workspace_id',$w)->where('id',$id)->firstOrFail();$cfg=app(TwilioVoiceConnection::class)->read();abort_unless($w===1&&$cfg&&in_array($route->number,$this->numbers($w,$cfg),true),409);
  abort_if(DB::table('voice_inbound_calls')->whereNull('capacity_released_at')->exists()||DB::table('voice_outbound_calls')->whereNull('capacity_released_at')->exists(),409,'Conclua as chamadas antes de alterar a rota.');
  $base='https://api.twilio.com/2010-04-01/Accounts/'.$cfg['account_sid'].'/IncomingPhoneNumbers';$http=Http::withBasicAuth($cfg['api_key'],$cfg['api_secret'])->asForm()->connectTimeout(3)->timeout(10)->withoutRedirecting();
  $r=$http->get($base.'.json',['PhoneNumber'=>$route->number,'PageSize'=>2]);abort_unless($r->successful()&&count($r->json('incoming_phone_numbers')??[])===1&&!$r->json('next_page_uri'),502,'A Twilio não confirmou o número.');$n=$r->json('incoming_phone_numbers')[0];abort_unless(($n['account_sid']??'')===$cfg['account_sid']&&($n['phone_number']??'')===$route->number&&preg_match('/^PN[a-fA-F0-9]{32}$/D',$n['sid']??''),502);
  abort_if(!empty($n['trunk_sid'])||!empty($n['voice_application_sid'])||(!empty($n['voice_url'])&&$n['voice_url']!==self::BASE)||(!empty($n['status_callback'])&&$n['status_callback']!==self::BASE.'/status'),409,'O número possui outra rota. Revise-a antes de substituir.');
  if(!app()->environment('testing')){$dir=storage_path('app/private/voice/inbound');if(!is_dir($dir))mkdir($dir,0700,true);$file=$dir.'/'.$n['sid'].'.enc';if(!is_file($file)){file_put_contents($file,\Illuminate\Support\Facades\Crypt::encryptString(json_encode($n)),LOCK_EX);chmod($file,0600);}}
  if(($n['voice_url']??'')!==self::BASE||($n['status_callback']??'')!==self::BASE.'/status'||($n['voice_method']??'')!=='POST'||($n['status_callback_method']??'')!=='POST'){
   $r=$http->post($base.'/'.$n['sid'].'.json',['VoiceUrl'=>self::BASE,'VoiceMethod'=>'POST','StatusCallback'=>self::BASE.'/status','StatusCallbackMethod'=>'POST']);abort_unless($r->successful()&&$r->json('sid')===$n['sid']&&$r->json('voice_url')===self::BASE&&$r->json('status_callback')===self::BASE.'/status',502,'Rota sem confirmação. Consulte novamente antes de testar.');
  }
  DB::table('voice_inbound_routes')->where('id',$id)->update(['provider_number_sid'=>$n['sid'],'provider_synced_at'=>now(),'updated_at'=>now()]);app(VoiceLab::class)->audit($w,$u,'inbound.route_published',$id);return ['configured'=>true,'number'=>$route->number];
 }
 private function stopCadences(object $c):void {
  $contact=DB::table('voice_contacts')->where('workspace_id',$c->workspace_id)->where('phone',$c->from_number)->first();if(!$contact)return;DB::table('voice_contacts')->where('id',$contact->id)->whereNull('replied_at')->update(['replied_at'=>now(),'updated_at'=>now()]);foreach(['voice_followups','voice_actions'] as $table)DB::table($table)->where('workspace_id',$c->workspace_id)->where('contact_id',$contact->id)->whereIn('status',['pending','blocked'])->update(['status'=>'cancelled','reason'=>'Conversa receptiva atendida.','updated_at'=>now()]);
 }
 private function http(object $c):\Illuminate\Http\Client\PendingRequest {$cfg=app(TwilioVoiceConnection::class)->read();abort_unless($cfg && $cfg['account_sid']===$c->account_sid,409);return Http::withBasicAuth($cfg['api_key'],$cfg['api_secret'])->asForm()->connectTimeout(3)->timeout(8)->withoutRedirecting();}
 private function callUrl(object $c):string{return 'https://api.twilio.com/2010-04-01/Accounts/'.$c->account_sid.'/Calls/'.$c->call_sid.'.json';}
 public function reconcile(object $c):array {$r=$this->http($c)->get($this->callUrl($c));abort_unless($r->successful()&&$r->json('sid')===$c->call_sid&&$r->json('account_sid')===$c->account_sid,502,'A Twilio não confirmou a chamada.');$this->ended(['AccountSid'=>$c->account_sid,'CallSid'=>$c->call_sid,'CallStatus'=>$r->json('status'),'CallDuration'=>$r->json('duration')]);return ['status'=>$r->json('status')];}
}
