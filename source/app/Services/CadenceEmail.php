<?php
namespace App\Services;
use Illuminate\Support\Facades\{DB,URL};
use Illuminate\Support\Str;

/** A separate durable step; no replay of old calls or automatic retry after SMTP submission. */
class CadenceEmail {
 public function address(object $c):?string {$a=strtolower(trim((string)(json_decode($c->fields??'{}',true)['email']??'')));return strlen($a)<=254&&filter_var($a,FILTER_VALIDATE_EMAIL)?$a:null;}
 public function threshold(array $s):int {return (int)(!empty($s['whatsapp_enabled'])?$s['whatsapp_after']:($s['max_attempts']??5));}
 public function observe(object $call):void {
  if(VoiceAnswering::stops($call)){DB::table('voice_email_followups')->where('workspace_id',$call->workspace_id)->where('contact_id',$call->contact_id)->whereIn('status',['pending','blocked'])->update(['status'=>'cancelled','reason'=>'Contato atendido por voz.','updated_at'=>now()]);return;}
  if(!$call->campaign_id||!$call->started_at||!$call->ended_at)return;
  $e=DB::table('voice_email_settings')->where('campaign_id',$call->campaign_id)->where('workspace_id',$call->workspace_id)->first();if(!$e?->enabled||$call->created_at<=$e->updated_at)return;
  $c=DB::table('voice_campaigns')->find($call->campaign_id);if(!$c||$c->followup_revision!==$call->campaign_revision)return;
  if($call->run_id&&!app(CadenceReentry::class)->current($c->id,$call->contact_id))return;
  $calls=DB::table('voice_outbound_calls')->where('campaign_id',$c->id)->where('contact_id',$call->contact_id)->where('run_id',$call->run_id)->where('campaign_revision',$c->followup_revision)->where('created_at','>',$e->updated_at)->whereNotNull('started_at');
  if((clone $calls)->whereRaw('(('.VoiceAnswering::stopsSql().') or ('.VoiceAnswering::reviewSql().'))')->exists())return;
  if((clone $calls)->whereNotNull('ended_at')->whereRaw(VoiceAnswering::missedSql())->count()<$this->threshold(json_decode($c->settings,true)))return;
  $contact=DB::table('voice_contacts')->find($call->contact_id);$address=$this->address($contact);
  DB::table('voice_email_followups')->insertOrIgnore(['id'=>(string)Str::uuid(),'workspace_id'=>$c->workspace_id,'campaign_id'=>$c->id,'contact_id'=>$contact->id,'run_id'=>$call->run_id,'cycle_key'=>$call->run_id??'legacy','call_id'=>$call->id,'settings_revision'=>$e->revision,'campaign_revision'=>$c->followup_revision,'destination'=>$address,'status'=>$address?'pending':'blocked','reason'=>$address?null:'Contato sem endereço de e-mail válido.','due_at'=>now()->addMinutes($e->delay_minutes),'created_at'=>now(),'updated_at'=>now()]);
 }
 public function eligible(object $f):array {
  $campaign=DB::table('voice_campaigns')->where('workspace_id',$f->workspace_id)->find($f->campaign_id);$e=DB::table('voice_email_settings')->where('workspace_id',$f->workspace_id)->where('campaign_id',$f->campaign_id)->first();
  abort_unless($campaign&&$e?->enabled&&$e->revision===$f->settings_revision&&$campaign->followup_revision===$f->campaign_revision,422,'Configuração alterada ou e-mail desativado.');
  abort_unless($campaign->status==='testing',422,'Cadência encerrada ou pausada.');$s=json_decode($campaign->settings,true);
  $contact=DB::table('voice_contacts')->where('workspace_id',$f->workspace_id)->find($f->contact_id);
  abort_unless($contact&&$contact->consent&&$contact->consent_evidence&&!$contact->suppressed_at&&!app(CadenceReentry::class)->replyBlocks($contact,$f->run_id),422,'Contato atendido, sem autorização ou com abordagem encerrada.');
  if($f->run_id)abort_unless(app(CadenceReentry::class)->current($campaign->id,$contact->id)?->id===$f->run_id,422,'Participação encerrada.');
  $call=DB::table('voice_outbound_calls')->find($f->call_id);abort_unless($call&&$call->destination===$contact->phone,422,'Telefone do contato alterado após a tentativa.');
  abort_unless($this->address($contact)===$f->destination&&$f->destination,422,'E-mail ausente ou alterado.');
  abort_if(DB::table('voice_email_optouts')->where('workspace_id',$f->workspace_id)->where('address_hash',hash('sha256',$f->destination))->exists(),422,'E-mail descadastrado.');
  abort_unless(DB::table('voice_members')->where('campaign_id',$campaign->id)->where('contact_id',$contact->id)->exists(),422,'Contato fora da cadência.');
  $p=DB::table('voice_campaign_policies')->where('campaign_id',$campaign->id)->first();
  if($p?->list_id){app(Segments::class)->refresh($f->workspace_id,'voice',$p->list_id);abort_unless(DB::table('voice_list_members')->where('list_id',$p->list_id)->where('contact_id',$contact->id)->where('status','active')->exists(),422,'Contato fora do segmento.');}
  $reason=app(VoiceAudience::class)->reason($f->workspace_id,$p,$contact);abort_if($reason,422,$reason??'');
  abort_if($p?->expires_at&&now()->gte($p->expires_at),422,'Prazo da cadência encerrado.');
  abort_if(DB::table('voice_outbound_calls')->where('workspace_id',$f->workspace_id)->where('contact_id',$contact->id)->where(fn($q)=>$q->whereNull('capacity_released_at')->orWhereRaw(VoiceAnswering::reviewSql())->orWhere(fn($q)=>$q->where('created_at','>=',$f->created_at)->whereRaw(VoiceAnswering::stopsSql())))->exists(),422,'Contato em chamada, atendido ou com resultado inconclusivo.');
  return [$campaign,$contact,$e];
 }
 public function dispatch(string $id):void {
  $prepared=DB::transaction(function()use($id){
   DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$f=DB::table('voice_email_followups')->find($id);if(!$f||$f->status!=='pending'||now()->lt($f->due_at))return null;
   $c=DB::table('voice_campaigns')->find($f->campaign_id);if($c&&($c->status==='paused'||!app(VoiceLab::class)->window(json_decode($c->settings,true))))return null;
   if(app(OperationPolicy::class)->get($f->workspace_id)['paused'])return null;
   try{[$c,$contact,$e]=$this->eligible($f);}catch(\Throwable){DB::table('voice_email_followups')->where('id',$id)->update(['status'=>'cancelled','reason'=>'Passo cancelado: configuração, participação ou autorização mudou.','updated_at'=>now()]);return null;}
   if(!app(CadenceEmailTransport::class)->ready()){DB::table('voice_email_followups')->where('id',$id)->update(['status'=>'blocked','reason'=>'SMTP, remetente e endereço de resposta precisam ser liberados pela administração.','updated_at'=>now()]);return null;}
   $used=DB::table('voice_email_followups')->where('workspace_id',$f->workspace_id)->where('submitted_at','>=',now()->startOfDay())->count();if($used>=config('cadence_email.daily_limit'))return null;
   $personalize=app(VoicePersonalization::class);$subject=preg_replace('/[\r\n]+/',' ',$personalize->render($e->subject,$contact,$c));$body=$personalize->render($e->body,$contact,$c);
   $url=URL::signedRoute('cadence.email.unsubscribe',['id'=>$id]);
   DB::table('voice_email_followups')->where('id',$id)->update(['status'=>'submitting','submitted_at'=>now(),'reason'=>null,'updated_at'=>now()]);
   return [$f,$subject,$body."\n\nPara não receber mais estes e-mails, confirme seu descadastro:\n".$url];
  });
  if(!$prepared)return;[$f,$subject,$body]=$prepared;
  try{app(CadenceEmailTransport::class)->send($id,$f->destination,$subject,$body);DB::table('voice_email_followups')->where('id',$id)->update(['status'=>'accepted','accepted_at'=>now(),'updated_at'=>now()]);}
  catch(\Throwable){DB::table('voice_email_followups')->where('id',$id)->update(['status'=>'unknown','reason'=>'O envio foi iniciado, mas a aceitação não foi confirmada. Confira o provedor; não haverá reenvio automático.','updated_at'=>now()]);}
 }
 public function run():void {
  DB::table('voice_email_followups')->where('status','submitting')->where('updated_at','<',now()->subMinutes(5))->update(['status'=>'unknown','reason'=>'Processamento interrompido após início do envio. Confira o provedor.','updated_at'=>now()]);
  foreach(DB::table('voice_email_followups')->where('status','pending')->where('due_at','<=',now())->orderBy('due_at')->limit(20)->pluck('id')as $id)$this->dispatch($id);
 }
 public function retry(int $w,int $campaign,string $id):void {
  DB::transaction(function()use($w,$campaign,$id){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$f=DB::table('voice_email_followups')->where('workspace_id',$w)->where('campaign_id',$campaign)->find($id);abort_unless($f,404);abort_unless($f->status==='blocked'&&!$f->submitted_at,422,'Uma tentativa já iniciada nunca é reenviada por esta ação.');$contact=DB::table('voice_contacts')->find($f->contact_id);$f->destination=$this->address($contact);$this->eligible($f);DB::table('voice_email_followups')->where('id',$id)->update(['destination'=>$f->destination,'status'=>'pending','reason'=>null,'updated_at'=>now()]);});
 }
 public function unsubscribe(string $id):void {
  DB::transaction(function()use($id){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$f=DB::table('voice_email_followups')->find($id);abort_unless($f&&$f->submitted_at&&$f->destination,404);DB::table('voice_email_optouts')->insertOrIgnore(['workspace_id'=>$f->workspace_id,'address_hash'=>hash('sha256',$f->destination),'created_at'=>now()]);DB::table('voice_email_followups')->where('workspace_id',$f->workspace_id)->where('destination',$f->destination)->whereIn('status',['pending','blocked'])->update(['status'=>'cancelled','reason'=>'Descadastro de e-mail confirmado.','updated_at'=>now()]);});
 }
}
