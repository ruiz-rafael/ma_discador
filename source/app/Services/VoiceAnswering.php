<?php
namespace App\Services;
use Illuminate\Support\Facades\{DB,Http,Validator};
use Twilio\TwiML\VoiceResponse;

/** Network answer and detected human are different facts. No call creation here. */
class VoiceAnswering {
 public const DEFAULTS=['mode'=>'off','count_machine_as_missed'=>false,'audio_asset_id'=>null,'audio_generic_confirmed'=>false];
 public const RESULTS=['human','machine_start','machine_end_beep','machine_end_silence','machine_end_other','fax','unknown'];
 public static function connectedSql(string $p=''):string {return "({$p}answered_at is not null or {$p}status='completed')";}
 public static function stopsSql(string $p=''):string {return self::connectedSql($p)." and ({$p}amd_mode='off' or {$p}answer_classification='human' or {$p}human_confirmed=true)";}
 public static function reviewSql(string $p=''):string {return self::connectedSql($p)." and {$p}amd_mode!='off' and {$p}answer_classification in ('pending','unknown') and coalesce({$p}human_confirmed,false)=false";}
 public static function missedSql(string $p=''):string {return "({$p}status='no_answer' and {$p}answered_at is null or ({$p}answer_classification='machine' and {$p}amd_count_machine=true and coalesce({$p}human_confirmed,false)=false))";}
 public static function stops(object $c):bool {return ($c->answered_at||$c->status==='completed')&&(($c->amd_mode??'off')==='off'||($c->answer_classification??'')==='human'||$c->human_confirmed);}
 public static function tabulation(object $c):bool {return ($c->answered_at||$c->status==='completed')&&(($c->amd_mode??'off')==='off'||in_array($c->answer_classification,['human','pending','unknown'])||$c->human_confirmed);}
 public function validate(int $w,array $d):array {
  $v=Validator::make($d,['mode'=>'required|in:off,detect,message','count_machine_as_missed'=>'required|boolean','audio_asset_id'=>'nullable|uuid','audio_generic_confirmed'=>'required|boolean'])->validate();
  if($v['mode']==='message'){abort_unless($v['audio_generic_confirmed']&&!empty($v['audio_asset_id']),422,'Selecione e confira um recado que possa ser ouvido por todos os contatos.');$this->audio($w,$v['audio_asset_id']);}
  return $v+self::DEFAULTS;
 }
 private function audio(int $w,string $id):string {
  $s=app(SpeechStudio::class);$a=$s->asset($w,$id);abort_unless($a->status==='ready'&&$a->duration>0&&$a->duration<=60&&now()->addMinutes(15)->lt($a->expires_at),422,'Gere um áudio de até 60 segundos, disponível por mais 15 minutos, antes de usar o recado.');return $s->file($w,$id,'wav');
 }
 public function snapshot(int $w,string $method,array $settings):array {
  $s=$settings['answering']??self::DEFAULTS;if($s['mode']==='off')return [];
  abort_unless($method==='programmable_voice',422,'A identificação de caixa postal está disponível somente na Twilio API. Desative-a para usar SIP.');$s=$this->validate($w,$s);
  return ['amd_mode'=>$s['mode'],'answer_classification'=>'pending','amd_count_machine'=>$s['count_machine_as_missed'],'voicemail_asset_id'=>$s['mode']==='message'?$s['audio_asset_id']:null,'voicemail_status'=>$s['mode']==='message'?'waiting':'none'];
 }
 public function options(object $c):array {return $c->amd_mode==='off'?[]:['machineDetection'=>$c->amd_mode==='message'?'DetectMessageEnd':'Enable','machineDetectionTimeout'=>30,'amdStatusCallback'=>TwilioVoiceConnection::base().'/amd/'.$c->id,'amdStatusCallbackMethod'=>'POST'];}
 public function record(string $id,string $account,string $child,string $by,?int $duration):void {
  DB::transaction(function()use($id,$account,$child,$by,$duration){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$c=DB::table('voice_outbound_calls')->find($id);abort_unless($c&&$c->method==='programmable_voice'&&$c->provider_account===$account&&$c->amd_mode!=='off'&&$c->channel_id,403);abort_if($c->provider_child_sid&&$c->provider_child_sid!==$child,403);abort_unless(in_array($by,self::RESULTS),422);
   DB::table('voice_answer_events')->insertOrIgnore(['call_id'=>$id,'child_sid'=>$child,'answered_by'=>$by,'duration_ms'=>$duration,'created_at'=>now(),'updated_at'=>now()]);$this->drain($id);
  });
 }
 /** Progress may arrive after AMD. Bind results only to the proven child CallSid. Under runtime lock. */
 public function drain(string $id):void {
  $c=DB::table('voice_outbound_calls')->find($id);if(!$c||$c->amd_mode==='off'||!$c->provider_child_sid)return;
  foreach(DB::table('voice_answer_events')->where('call_id',$id)->where('child_sid',$c->provider_child_sid)->whereNull('processed_at')->orderBy('id')->get() as $event){
   DB::table('voice_answer_events')->where('id',$event->id)->update(['processed_at'=>now()]);
   if($c->amd_answered_by||$c->amd_reviewed_at){if($c->amd_answered_by!==$event->answered_by)app(VoiceLab::class)->audit($c->workspace_id,null,'calling.amd.conflicting_result',$id,['result'=>$event->answered_by]);continue;}
   $class=str_starts_with($event->answered_by,'machine_')?'machine':$event->answered_by;
   $v=['answered_at'=>$c->answered_at??now(),'answer_classification'=>$class,'amd_answered_by'=>$event->answered_by,'amd_duration_ms'=>$event->duration_ms,'amd_completed_at'=>now(),'updated_at'=>now()];
   if($c->amd_mode==='message')$v+=['voicemail_status'=>$event->answered_by==='machine_end_beep'&&!$c->capacity_released_at?'planned':'skipped','voicemail_reason'=>$event->answered_by==='machine_end_beep'&&!$c->capacity_released_at?null:'Sem sinal de gravação confirmado ou chamada já encerrada.'];
   DB::table('voice_outbound_calls')->where('id',$id)->update($v);app(VoiceLab::class)->audit($c->workspace_id,null,'calling.amd.classified',$id,['classification'=>$class,'result'=>$event->answered_by]);$c=DB::table('voice_outbound_calls')->find($id);
  }
  app(VoiceFollowups::class)->observe($id);
 }
 public function expire():void {DB::table('voice_outbound_calls')->where('answer_classification','pending')->where('started_at','<',now()->subSeconds(90))->where(fn($q)=>$q->whereNotNull('answered_at')->orWhere('status','completed'))->update(['answer_classification'=>'unknown','amd_completed_at'=>now(),'updated_at'=>now()]);}
 private function permitted(object $c):void {
  abort_unless($c->amd_mode==='message'&&$c->answer_classification==='machine'&&$c->amd_answered_by==='machine_end_beep'&&!$c->capacity_released_at&&now()->lt($c->deadline_at),409);
  abort_if(app(OperationPolicy::class)->get($c->workspace_id)['paused'],409);
  $contact=DB::table('voice_contacts')->find($c->contact_id);abort_unless($contact&&$contact->workspace_id===$c->workspace_id&&$contact->phone===$c->destination&&$contact->consent&&$contact->consent_evidence&&!$contact->suppressed_at&&!app(CadenceReentry::class)->replyBlocks($contact,$c->run_id),409);
  $campaign=DB::table('voice_campaigns')->find($c->campaign_id);abort_unless($campaign&&$campaign->status==='testing'&&$campaign->followup_revision===$c->campaign_revision&&DB::table('voice_members')->where('campaign_id',$c->campaign_id)->where('contact_id',$c->contact_id)->exists(),409);
  if($c->run_id)abort_unless(app(CadenceReentry::class)->current($c->campaign_id,$c->contact_id)?->id===$c->run_id,409);
  $policy=DB::table('voice_campaign_policies')->where('campaign_id',$c->campaign_id)->first();
  abort_if(app(VoiceAudience::class)->reason($c->workspace_id,$policy,$contact),409);
  if($policy?->list_id)abort_unless(DB::table('voice_list_members')->where('list_id',$policy->list_id)->where('contact_id',$contact->id)->where('status','active')->exists(),409);
  $this->audio($c->workspace_id,$c->voicemail_asset_id);
 }
 /** One update of an existing call. Persist the claim before HTTP; ambiguous writes are never retried. */
 public function deliver(string $id):void {
  $c=DB::transaction(function()use($id){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$c=DB::table('voice_outbound_calls')->find($id);if(!$c||$c->voicemail_status!=='planned')return null;
   try{$this->permitted($c);}catch(\Throwable){DB::table('voice_outbound_calls')->where('id',$id)->update(['voicemail_status'=>'skipped','voicemail_reason'=>'Recado indisponível ou abordagem interrompida.']);return null;}
   DB::table('voice_outbound_calls')->where('id',$id)->update(['voicemail_status'=>'submitting','voicemail_requested_at'=>now(),'updated_at'=>now()]);return $c;
  });if(!$c)return;
  $status='unconfirmed';$reason='Solicitação sem confirmação. Não haverá repetição automática.';
  try{$connection=app(TwilioVoiceConnection::class)->read();abort_unless($connection&&$connection['account_sid']===$c->provider_account,409);$r=Http::withBasicAuth($connection['api_key'],$connection['api_secret'])->asForm()->connectTimeout(3)->timeout(8)->withoutRedirecting()->post('https://api.twilio.com/2010-04-01/Accounts/'.$c->provider_account.'/Calls/'.$c->provider_child_sid.'.json',['Url'=>TwilioVoiceConnection::base().'/voicemail/'.$id,'Method'=>'POST']);
   if($r->successful()&&$r->json('sid')===$c->provider_child_sid){$status='instructed';$reason=null;}elseif($r->clientError()){$status='failed';$reason='A operadora recusou a reprodução do recado.';}
  }catch(\Throwable){}
  DB::table('voice_outbound_calls')->where('id',$id)->where('voicemail_status','submitting')->update(['voicemail_status'=>$status,'voicemail_reason'=>$reason,'updated_at'=>now()]);
 }
 public function play(string $id,string $account,string $child,bool $finished=false):string {
  return DB::transaction(function()use($id,$account,$child,$finished){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$c=DB::table('voice_outbound_calls')->find($id);abort_unless($c&&$c->provider_account===$account&&$c->provider_child_sid===$child&&$c->amd_mode==='message',403);$xml=new VoiceResponse;
   if($finished){if($c->voicemail_started_at&&in_array($c->voicemail_status,['playing','unconfirmed'],true))DB::table('voice_outbound_calls')->where('id',$id)->update(['voicemail_status'=>'played','voicemail_played_at'=>now(),'updated_at'=>now()]);$xml->hangup();return (string)$xml;}
   if($c->voicemail_started_at||!in_array($c->voicemail_status,['submitting','instructed','unconfirmed'],true)){$xml->hangup();return (string)$xml;}
   try{$this->permitted($c);$url=app(SpeechStudio::class)->deliveryUrl($c->workspace_id,$c->voicemail_asset_id,'wav');}catch(\Throwable){DB::table('voice_outbound_calls')->where('id',$id)->update(['voicemail_status'=>'skipped','voicemail_reason'=>'Recado indisponível ou abordagem interrompida.']);$xml->hangup();return (string)$xml;}
   DB::table('voice_outbound_calls')->where('id',$id)->update(['voicemail_status'=>'playing','voicemail_started_at'=>now(),'updated_at'=>now()]);$xml->play($url);$xml->redirect(TwilioVoiceConnection::base().'/voicemail-finished/'.$id,['method'=>'POST']);return (string)$xml;
  });
 }
 public function reviewed(object $call,object $code):void {if($call->amd_mode==='off'||!($call->answered_at||$call->status==='completed'))return;if($code->human||$code->code==='machine'){DB::table('voice_outbound_calls')->where('id',$call->id)->update(['answer_classification'=>$code->human?'human':'machine','amd_reviewed_at'=>now()]);app(VoiceFollowups::class)->observe($call->id);}}
}
