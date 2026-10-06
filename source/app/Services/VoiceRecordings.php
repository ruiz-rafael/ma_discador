<?php
namespace App\Services;
use Illuminate\Support\Facades\{DB,Http};
class VoiceRecordings {
 public function call(string $kind,string $id):object {abort_unless(in_array($kind,['inbound','outbound'],true),404);return DB::table('voice_'.$kind.'_calls')->find($id)??abort(404);}
 public function options(string $kind,object $c):array {
  $q=$c->queue_id?DB::table('voice_live_queues')->find($c->queue_id):null;
  if(!$q?->recording_enabled)return ['record'=>'do-not-record'];
  $policy=['enabled'=>true,'agent_access'=>(bool)$q->recording_agent_access,'agent_pause'=>(bool)$q->recording_agent_pause,'retention_days'=>$q->recording_retention_days];
  if(!$c->recording_policy)DB::table('voice_'.$kind.'_calls')->where('id',$c->id)->update(['recording_policy'=>json_encode($policy)]);
  return ['record'=>'record-from-answer-dual','recordingStatusCallback'=>TwilioVoiceConnection::BASE.'/recordings/'.$kind.'/'.$c->id,'recordingStatusCallbackMethod'=>'POST','recordingStatusCallbackEvent'=>'in-progress completed absent'];
 }
 public function callback(string $kind,string $id,array $d):void {DB::transaction(function()use($kind,$id,$d){
  DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$c=$this->call($kind,$id);$policy=json_decode($c->recording_policy??'{}',true);$parent=$kind==='inbound'?$c->call_sid:$c->channel_id;$account=$kind==='inbound'?$c->account_sid:$c->provider_account;
  abort_unless(($policy['enabled']??false)&&$account===$d['AccountSid']&&$parent===$d['CallSid'],403);
  $old=DB::table('voice_recordings')->where('id',$d['RecordingSid'])->lockForUpdate()->first();
  abort_if($old&&($old->call_id!==$id||$old->kind!==$kind),409);
  if($old&&($old->deleted_at||in_array($old->status,['completed','absent'],true)))return;
  // A delayed initial callback must not overwrite an explicit pause/resume response.
  if($old?->control_at&&$d['RecordingStatus']==='in-progress')return;
  $v=['status'=>$d['RecordingStatus'],'duration'=>$d['RecordingDuration']??null,'updated_at'=>now()];
  if($old)DB::table('voice_recordings')->where('id',$old->id)->update($v);
  else DB::table('voice_recordings')->insert($v+['id'=>$d['RecordingSid'],'workspace_id'=>$c->workspace_id,'kind'=>$kind,'call_id'=>$id,'user_id'=>$c->user_id,'account_sid'=>$account,'parent_sid'=>$parent,'expires_at'=>now()->addDays($policy['retention_days']),'created_at'=>now()]);
 });}
 private function authorize(object $u,object $c):array {
  abort_unless($u->voice_enabled&&$u->voice_workspace_id===$c->workspace_id,403);$manage=in_array($u->voice_role,['admin','supervisor'],true);$policy=json_decode($c->recording_policy??'{}',true);
  abort_unless($manage||($c->user_id===$u->id&&($policy['agent_access']??false)),403,'Acesso à gravação reservado à supervisão.');return [$manage,$policy];
 }
 public function state(object $u,string $kind,string $id):array {
  $c=$this->call($kind,$id);abort_unless($u->voice_workspace_id===$c->workspace_id&&$u->voice_enabled,403);$manage=in_array($u->voice_role,['admin','supervisor'],true);abort_unless($manage||$c->user_id===$u->id,403);
  $policy=json_decode($c->recording_policy??'{}',true);if(!($policy['enabled']??false))return ['enabled'=>false,'recordings'=>[]];
  if(!$manage&&!($policy['agent_access']??false))return ['enabled'=>true,'restricted'=>true,'recordings'=>[]];
  $rows=DB::table('voice_recordings')->where('kind',$kind)->where('call_id',$id)->orderBy('created_at')->get(['id','status','duration','expires_at','deleted_at'])->map(function($r)use($c,$manage,$policy){$r->can_control=!$c->capacity_released_at&&($manage||($policy['agent_pause']??false))&&in_array($r->status,['in-progress','paused'],true);$r->can_play=$r->status==='completed'&&!$r->deleted_at&&now()->lt($r->expires_at);return $r;});
  return ['enabled'=>true,'recordings'=>$rows];
 }
 private function connection(object $r):array {$c=app(TwilioVoiceConnection::class)->read();abort_unless($c&&$r->account_sid===$c['account_sid'],409,'A conexão original da gravação não está disponível.');return $c;}
 private function http(array $c){return Http::withBasicAuth($c['api_key'],$c['api_secret'])->connectTimeout(3)->timeout(15)->withoutRedirecting();}
 private function base(object $r):string{return 'https://api.twilio.com/2010-04-01/Accounts/'.$r->account_sid;}
 public function control(object $u,string $sid,string $status):void {DB::transaction(function()use($u,$sid,$status){
  $r=DB::table('voice_recordings')->where('id',$sid)->lockForUpdate()->firstOrFail();$c=$this->call($r->kind,$r->call_id);[$manage,$policy]=$this->authorize($u,$c);
  abort_unless($manage||($policy['agent_pause']??false),403,'Esta fila não permite ao agente pausar a gravação.');abort_unless(!$c->capacity_released_at&&!$r->deleted_at&&now()->lt($r->expires_at)&&in_array($r->status,['in-progress','paused'],true),409,'A gravação não está em andamento.');
  if($r->status===$status)return;
  $res=$this->http($this->connection($r))->asForm()->post($this->base($r).'/Calls/'.$r->parent_sid.'/Recordings/'.$r->id.'.json',['Status'=>$status,'PauseBehavior'=>'skip']);
  abort_unless($res->successful()&&$res->json('sid')===$r->id&&$res->json('account_sid')===$r->account_sid&&$res->json('call_sid')===$r->parent_sid&&$res->json('status')===$status,502,'A operadora não confirmou a alteração da gravação. Atualize antes de tentar novamente.');
  DB::table('voice_recordings')->where('id',$sid)->update(['status'=>$status,'control_at'=>now(),'updated_at'=>now()]);app(VoiceLab::class)->audit($c->workspace_id,$u->id,'recording.'.$status,$sid);
 });}
 public function audio(object $u,string $sid):string {
  $r=DB::table('voice_recordings')->find($sid)??abort(404);$this->authorize($u,$this->call($r->kind,$r->call_id));abort_unless($r->status==='completed'&&!$r->deleted_at&&now()->lt($r->expires_at),410,'Gravação indisponível ou fora do prazo de retenção.');
  $res=$this->http($this->connection($r))->get($this->base($r).'/Recordings/'.$sid.'.mp3');
  if($res->redirect()){$url=$res->header('Location');$host=parse_url($url,PHP_URL_HOST)??'';abort_unless(parse_url($url,PHP_URL_SCHEME)==='https'&&!parse_url($url,PHP_URL_USER)&&preg_match('/\.(?:amazonaws\.com|twiliocdn\.com)$/D',$host),502,'Endereço de áudio não reconhecido.');$res=Http::connectTimeout(3)->timeout(30)->withoutRedirecting()->get($url);}
  abort_unless($res->successful()&&str_starts_with($res->header('Content-Type'),'audio/')&&strlen($res->body())<=64*1024*1024,502,'A operadora não disponibilizou o áudio.');app(VoiceLab::class)->audit($r->workspace_id,$u->id,'recording.played',$sid);return $res->body();
 }
 public function purge():int {$count=0;foreach(DB::table('voice_recordings')->whereNull('deleted_at')->where('expires_at','<=',now())->limit(100)->get()as $r){try{$res=$this->http($this->connection($r))->delete($this->base($r).'/Recordings/'.$r->id.'.json');if($res->successful()||$res->status()===404){DB::table('voice_recordings')->where('id',$r->id)->update(['deleted_at'=>now(),'status'=>'deleted','updated_at'=>now()]);app(VoiceLab::class)->audit($r->workspace_id,null,'recording.retention_deleted',$r->id);$count++;}}catch(\Throwable){/* Retain metadata and retry on the next scheduled run. */}}return $count;}
}
