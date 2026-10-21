<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Incoming carrier calls never use an outbound destination supplied by a browser. */
class SipInbound
{
 public function numbers():array {
  $n=[];
  foreach(DB::table('voice_sip_trunks')->where('enabled',true)->get() as $row)$n[$row->number]=['endpoint'=>app(SipTrunks::class)->endpoint($row),'provider'=>'fale_vono','limit'=>app(SipTrunks::class)->config($row)['concurrency'],'account'=>str_replace('-','',$row->id)];
  if($t=app(TwilioSipRuntime::class)->descriptor())$n[$t['caller_id']]=['endpoint'=>$t['endpoint'],'provider'=>'twilio','limit'=>app(VoiceCallingConfig::class)->trunk()['planned_concurrency']??1,'account'=>app(VoiceCallingConfig::class)->trunk()['account_sid']];
  return $n;
 }
 public function agent(object $u):?array {
  $p=app(VoiceCallingConfig::class)->read();
  if(!$p||!$u->voice_enabled||!$u->voice_workspace_id)return null;
  $key=$u->voice_workspace_id.'|'.$u->id.'|'.$u->voice_auth_version;
  return ['username'=>'discai-in-'.substr(hash_hmac('sha256','identity|'.$key,$p['event_secret']),0,32),
   'password'=>hash_hmac('sha256','password|'.$key,$p['event_secret'])];
 }
 public function agents():array {
  $ids=DB::table('voice_live_queues')->whereIn('direction',['inbound','mixed'])->get()->flatMap(fn($q)=>json_decode($q->agent_ids,true))->unique();
  return User::where('voice_enabled',true)->whereIn('id',$ids)->get()->map(fn($u)=>$this->agent($u))->filter()->values()->all();
 }
 public function grant(int $u):?array {
  if(!$this->numbers())return null;
  $a=$this->agent(User::findOrFail($u));abort_unless($a,403);
  $origin=\App\Services\Portal\TenantUrls::origin();$host=parse_url($origin,PHP_URL_HOST);
  return $a+['aor'=>'sip:'.$a['username'].'@'.$host,'server'=>'wss://'.$host.'/voice/ws'];
 }
 public function routes():array {
  $n=$this->numbers();return DB::table('voice_inbound_routes')->where('provider','sip_trunk')->get()->filter(fn($r)=>isset($n[$r->number]))->map(fn($r)=>['number'=>$r->number,'endpoint'=>$n[$r->number]['endpoint']])->values()->all();
 }
 private function lock():void {DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();}
 public function event(array $d):array {
  return DB::transaction(function()use($d){
   $this->lock();$sid='SI'.md5($d['endpoint'].'|'.$d['channel_id']);
   $c=DB::table('voice_inbound_calls')->where('call_sid',$sid)->first();
   if($d['event']==='inbound_start'){
    if($c)return ['allowed'=>false]; // Duplicate admission must not ring twice.
    $n=$this->numbers();$route=DB::table('voice_inbound_routes')->where('number',$d['number'])->where('provider','sip_trunk')->where('enabled',true)->first();
    $q=$route?DB::table('voice_live_queues')->where('workspace_id',$route->workspace_id)->find($route->queue_id):null;
    if(!$route||($n[$d['number']]['endpoint']??null)!==$d['endpoint']||!QueueRouting::incoming($q)||!QueueOperationSettings::open($q)||app(OperationPolicy::class)->voiceReason($route->workspace_id))return ['allowed'=>false];
    $trunk=$n[$d['number']];$busy=DB::table('voice_inbound_calls')->where('account_sid','SI'.md5($d['endpoint']))->whereNull('capacity_released_at')->count()+DB::table('voice_outbound_calls')->where('method','sip_trunk')->where('caller_id',$route->number)->whereNull('capacity_released_at')->count();if($busy>=$trunk['limit'])return ['allowed'=>false];
    if(app(VoiceCapacity::class)->full()||DB::table('voice_audio_sessions')->whereIn('status',['pending','connecting','active'])->where('expires_at','>',now())->exists())return ['allowed'=>false];
    $id=(string)Str::uuid();
    DB::table('voice_inbound_calls')->insert(['id'=>$id,'workspace_id'=>$route->workspace_id,'route_id'=>$route->id,'queue_id'=>$q->id,'account_sid'=>'SI'.md5($d['endpoint']),'call_sid'=>$sid,'sip_channel'=>$d['channel_id'],'provider'=>'sip_trunk','from_number'=>$d['from']??'anonymous','to_number'=>$route->number,'status'=>'waiting','created_at'=>now(),'updated_at'=>now()]);
    app(VoiceLab::class)->audit($route->workspace_id,null,'inbound.sip_received',$id);
    return ['allowed'=>true,'wait_seconds'=>$route->wait_seconds,'max_seconds'=>min(1800,app(VoiceCallingConfig::class)->read()['max_seconds']??180)];
   }
   abort_unless($c&&$c->provider==='sip_trunk'&&$c->account_sid==='SI'.md5($d['endpoint'])&&$c->sip_channel===$d['channel_id'],403);
   if($d['event']==='inbound_end'){
    app(InboundVoice::class)->ended(['AccountSid'=>$c->account_sid,'CallSid'=>$c->call_sid,'CallStatus'=>'completed','CallDuration'=>$d['bill_seconds']??0]);
    return ['allowed'=>true];
   }
   if($c->capacity_released_at)return ['allowed'=>false];
   if($d['event']==='inbound_dispatch')return $this->dispatch($c);
   $o=DB::table('voice_inbound_offers')->where('call_id',$c->id)->find($d['offer_id']??'');abort_unless($o,403);
   if($d['event']==='inbound_answer'){
    if($o->status==='answered')return ['allowed'=>true];
    $u=DB::table('users')->find($o->user_id);
    if($o->status!=='offered'||!$u?->voice_enabled||$this->agent($u)['username']!==$o->identity)return ['allowed'=>false];
    DB::table('voice_inbound_offers')->where('id',$o->id)->update(['status'=>'answered','answered_at'=>now(),'updated_at'=>now()]);
    DB::table('voice_inbound_calls')->where('id',$c->id)->update(['status'=>'answered','answered_at'=>now(),'updated_at'=>now()]);
    app(QueueOperationSettings::class)->offerResult($c->queue_id,$o->user_id,true);app(InboundVoice::class)->stopCadences($c);
    return ['allowed'=>true];
   }
   if($d['event']==='inbound_result'){
    if(!in_array($o->status,['offered','answered'],true))return ['allowed'=>false];
    $answered=(bool)$o->answered_at;
    DB::table('voice_inbound_offers')->where('id',$o->id)->update(['status'=>$answered?'completed':'no-answer','ended_at'=>now(),'updated_at'=>now()]);
    if(!$answered)app(QueueOperationSettings::class)->offerResult($c->queue_id,$o->user_id,false);
    DB::table('voice_inbound_calls')->where('id',$c->id)->update(['status'=>$answered?'ending':'waiting','user_id'=>$answered?$o->user_id:null,'updated_at'=>now()]);
    return ['allowed'=>!$answered];
   }
   return ['allowed'=>false];
  });
 }
 private function dispatch(object $c):array {
  if($c->status!=='waiting')return ['allowed'=>false];
  $r=DB::table('voice_inbound_routes')->find($c->route_id);$q=DB::table('voice_live_queues')->find($c->queue_id);
  if(!$r?->enabled||!QueueRouting::incoming($q)||!QueueOperationSettings::open($q)||app(OperationPolicy::class)->get($c->workspace_id)['paused']||now()->gte(\Carbon\CarbonImmutable::parse($c->created_at)->addSeconds($r->wait_seconds))){
   DB::table('voice_inbound_calls')->where('id',$c->id)->update(['status'=>'unavailable','updated_at'=>now()]);return ['allowed'=>false];
  }
  $tried=DB::table('voice_inbound_offers')->where('call_id',$c->id)->pluck('user_id');
  $agent=app(QueueDistribution::class)->order($q,app(InboundVoice::class)->candidates($c,true)->filter(fn($a)=>!$tried->contains($a->id)),'inbound')->first();
  if(!$agent)return ['allowed'=>true,'waiting'=>true];
  $offer=app(InboundVoice::class)->offer($c,$agent);
  $remaining=max(1,\Carbon\CarbonImmutable::parse($c->created_at)->addSeconds($r->wait_seconds)->getTimestamp()-now()->getTimestamp());
  return ['allowed'=>true,'agent'=>$agent->identity,'offer_id'=>$offer,'ring_seconds'=>min($remaining,$q->agent_ring_seconds??$r->ring_seconds)];
 }
 /** Called only by the host reconciler after observing active PBX channels. */
 public function reconcileChannels(array $channels):void {
  DB::transaction(function()use($channels){$this->lock();foreach(DB::table('voice_inbound_calls')->where('provider','sip_trunk')->whereNull('capacity_released_at')->where('created_at','<',now()->subSeconds(30))->get() as $c){
   if(!in_array($c->sip_channel,$channels,true))app(InboundVoice::class)->ended(['AccountSid'=>$c->account_sid,'CallSid'=>$c->call_sid,'CallStatus'=>'completed','CallDuration'=>$c->answered_at?null:0]);
  }});
 }
}
