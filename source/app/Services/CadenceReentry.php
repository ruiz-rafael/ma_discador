<?php
namespace App\Services;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Support\Str;

/** All mutations run under voice_runtime. Enrollment never dials or sends a message. */
class CadenceReentry {
 public const REASONS=['answered','replied','attempts_exhausted','no_response','message_failed','source_left','cancelled'];
 public const DEFAULTS=['mode'=>'once','interval_days'=>30,'max_participations'=>3,'exit_reasons'=>['attempts_exhausted','no_response','source_left'],'reply_wait_hours'=>24];
 public function policy(object $c):?array {return json_decode($c->settings,true)['reentry']??null;}
 public function validate(array $p):array {
  $v=Validator::make($p,['mode'=>'required|in:once,segment_return,interval,event','interval_days'=>'required|integer|between:1,3650','max_participations'=>'required|integer|between:1,100','exit_reasons'=>'present|array|max:7','exit_reasons.*'=>'required|distinct|in:'.implode(',',self::REASONS),'reply_wait_hours'=>'required|integer|between:1,720'])->validate();
  abort_if($v['mode']!=='once'&&!$v['exit_reasons'],422,'Escolha quais encerramentos permitem reentrada.');return $v;
 }
 public function current(int $campaign,int $contact):?object {return DB::table('voice_cadence_runs')->where('campaign_id',$campaign)->where('contact_id',$contact)->whereIn('status',['active','waiting'])->first();}
 public function replyBlocks(object $contact,?string $run):bool {
  if(!$contact->replied_at)return false;
  $r=$run?DB::table('voice_cadence_runs')->find($run):null;
  return !$r||$r->contact_id!==$contact->id||!in_array($r->status,['active','waiting'],true)||!$r->reply_baseline||CarbonImmutable::parse($contact->replied_at)->gt($r->reply_baseline);
 }
 public function finish(object $r,string $reason):void {
  if(!in_array($r->status,['active','waiting'],true))return;
  DB::table('voice_cadence_runs')->where('id',$r->id)->whereIn('status',['active','waiting'])->update(['status'=>'completed','exit_reason'=>$reason,'ended_at'=>now(),'updated_at'=>now()]);
  DB::table('voice_followups')->where('run_id',$r->id)->whereIn('status',['pending','blocked'])->update(['status'=>'cancelled','reason'=>'Participação encerrada: '.$reason,'updated_at'=>now()]);
  app(VoiceLab::class)->audit($r->workspace_id,null,'cadence.exited',$r->id,['campaign_id'=>$r->campaign_id,'reason'=>$reason,'participation'=>$r->number]);
 }
 public function reconcile(object $r):void {
  $contact=DB::table('voice_contacts')->find($r->contact_id);
  if($contact->suppressed_at||!$contact->consent){$this->finish($r,'opt_out');return;}
  if($this->replyBlocks($contact,$r->id)){$this->finish($r,'replied');return;}
  $calls=DB::table('voice_outbound_calls')->where('run_id',$r->id);
  if((clone $calls)->where(fn($q)=>$q->whereNotNull('answered_at')->orWhere('status','completed'))->exists()){$this->finish($r,'answered');return;}
  if((clone $calls)->where(fn($q)=>$q->whereNull('capacity_released_at')->orWhere('status','unknown'))->exists())return;
  $f=DB::table('voice_followups')->where('run_id',$r->id)->first();
  if($f){
   if(in_array($f->status,['pending','blocked','dispatching','unknown','sending'],true))return;
   $m=$f->message_id?DB::table('wa_messages')->find($f->message_id):null;$status=$m?->status??$f->status;
   if(in_array($status,['failed','undelivered'],true)){$this->finish($r,'message_failed');return;}
   if($status==='cancelled'){$this->finish($r,'cancelled');return;}
   if(in_array($status,['sent','delivered','read'],true)){
    $since=$r->waiting_since??now();DB::table('voice_cadence_runs')->where('id',$r->id)->update(['status'=>'waiting','waiting_since'=>$since,'updated_at'=>now()]);
    if(now()->gte(CarbonImmutable::parse($since)->addHours(json_decode($r->policy,true)['reply_wait_hours'])))$this->finish($r,'no_response');
   }
   return;
  }
  $s=json_decode(DB::table('voice_campaigns')->where('id',$r->campaign_id)->value('settings'),true);
  if((clone $calls)->whereNotNull('started_at')->count()>=($s['max_attempts']??5))$this->finish($r,'attempts_exhausted');
 }
 private function create(object $c,object $contact,array $p,string $trigger,?string $event=null,bool $legacy=false):object {
  $number=(int)DB::table('voice_cadence_runs')->where('campaign_id',$c->id)->where('contact_id',$contact->id)->max('number')+1;$id=(string)Str::uuid();
  DB::table('voice_cadence_runs')->insert(['id'=>$id,'workspace_id'=>$c->workspace_id,'campaign_id'=>$c->id,'contact_id'=>$contact->id,'number'=>$number,'trigger'=>$trigger,'event_key'=>$event,'policy'=>json_encode($p),'reply_baseline'=>$legacy?null:$contact->replied_at,'entered_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
  app(VoiceLab::class)->audit($c->workspace_id,null,'cadence.entered',$id,['campaign_id'=>$c->id,'participation'=>$number,'trigger'=>$trigger]);return DB::table('voice_cadence_runs')->find($id);
 }
 /** Preserve the existing cycle on first explicit configuration; never reset its attempts. */
 public function adopt(object $c):void {
  $p=$this->policy($c);if(!$p)return;
  $ids=DB::table('voice_outbound_calls')->where('campaign_id',$c->id)->whereNull('run_id')->distinct()->pluck('contact_id');
  foreach($ids as $id){
   if(DB::table('voice_cadence_runs')->where('campaign_id',$c->id)->where('contact_id',$id)->exists())continue;
   $r=$this->create($c,DB::table('voice_contacts')->find($id),$p,'legacy',null,true);
   foreach(['voice_outbound_calls','voice_followups','wa_messages']as $t)DB::table($t)->where('campaign_id',$c->id)->where('contact_id',$id)->whereNull('run_id')->update(['run_id'=>$r->id]);
   DB::table('voice_live_reservations')->where('campaign_id',$c->id)->where('contact_id',$id)->whereNull('run_id')->update(['run_id'=>$r->id]);
   $this->reconcile($r);
  }
 }
 private function sourceIds(object $c):array {
  $p=DB::table('voice_campaign_policies')->where('campaign_id',$c->id)->first();
  if($p?->audience_id){app(VoiceAudience::class)->sync($c->workspace_id,$c->id);}
  elseif($p?->list_id){app(Segments::class)->refresh($c->workspace_id,'voice',$p->list_id);$ids=DB::table('voice_list_members')->where('list_id',$p->list_id)->where('status','active')->pluck('contact_id')->all();foreach($ids as $id)DB::table('voice_members')->insertOrIgnore(['campaign_id'=>$c->id,'contact_id'=>$id]);return $ids;}
  return DB::table('voice_members')->where('campaign_id',$c->id)->pluck('contact_id')->all();
 }
 public function sync(object $c,bool $enroll=true):void {
  $p=$this->policy($c);if(!$p)return;$this->adopt($c);$ids=$this->sourceIds($c);
  $known=DB::table('voice_cadence_membership')->where('campaign_id',$c->id)->get()->keyBy('contact_id');
  foreach(array_unique(array_merge($ids,$known->keys()->all(),DB::table('voice_cadence_runs')->where('campaign_id',$c->id)->whereIn('status',['active','waiting'])->pluck('contact_id')->all()))as $id){
   $present=in_array($id,$ids,true);$old=$known->get($id);$generation=($old?->generation??1)+($old&&!$old->present&&$present?1:0);
   DB::table('voice_cadence_membership')->updateOrInsert(['campaign_id'=>$c->id,'contact_id'=>$id],['present'=>$present,'generation'=>$generation,'consumed_generation'=>($old?->consumed_generation??(DB::table('voice_cadence_runs')->where('campaign_id',$c->id)->where('contact_id',$id)->exists()?1:0)),'created_at'=>$old?->created_at??now(),'updated_at'=>now()]);
   if($r=$this->current($c->id,$id)){
    if(!$present)$this->finish($r,'source_left');elseif(in_array($c->status,['completed','cancelled']))$this->finish($r,'cancelled');else $this->reconcile($r);
   }
   if($present&&$enroll&&$c->status==='testing'&&$p['mode']!=='event')$this->enter($c,$id,null,false);
  }
 }
 public function reason(object $c,int $contact,?string $event=null):?string {
  $p=$this->policy($c);if(!$p)return 'Configure a política de entrada no cartão Público da jornada.';
  $person=DB::table('voice_contacts')->where('workspace_id',$c->workspace_id)->find($contact);if(!$person)return 'Contato de outro workspace ou inexistente.';
  if(!$person->consent||!$person->consent_evidence||$person->suppressed_at)return 'Contato sem autorização ou com descadastro.';
  if($sourceReason=app(VoiceAudience::class)->reason($c->workspace_id,DB::table('voice_campaign_policies')->where('campaign_id',$c->id)->first(),$person))return $sourceReason;
  if($c->status!=='testing')return 'Cadência pausada ou encerrada.';
  $m=DB::table('voice_cadence_membership')->where('campaign_id',$c->id)->where('contact_id',$contact)->first();if(!$m?->present)return 'Contato fora do segmento ou público selecionado.';
  if($this->current($c->id,$contact))return 'Já existe uma participação em andamento.';
  if(DB::table('voice_outbound_calls')->where('workspace_id',$c->workspace_id)->where('contact_id',$contact)->whereNull('capacity_released_at')->exists()||DB::table('voice_live_reservations')->where('workspace_id',$c->workspace_id)->where('contact_id',$contact)->whereIn('status',['reserved','calling','tabulation'])->exists())return 'Conclua a chamada, reserva ou tabulação do contato.';
  if(DB::table('wa_conversations')->where('workspace_id',$c->workspace_id)->where('contact_id',$contact)->whereNotNull('last_inbound_at')->where('status','!=','closed')->exists())return 'Conclua a conversa WhatsApp antes de iniciar outra participação.';
  $previous=DB::table('voice_cadence_runs')->where('campaign_id',$c->id)->where('contact_id',$contact)->orderByDesc('number')->first();
  if($previous){
   if($p['mode']==='once')return 'Participação única: este contato já percorreu a jornada.';
   if($previous->number>=$p['max_participations'])return 'Limite de participações atingido.';
   if(!in_array($previous->exit_reason,$p['exit_reasons'],true))return 'O motivo de encerramento anterior não permite reentrada.';
   if($p['mode']==='interval'&&now()->lt(CarbonImmutable::parse($previous->ended_at)->addDays($p['interval_days'])))return 'Aguarde o intervalo desde o encerramento anterior.';
   if($p['mode']==='segment_return'&&$m->generation<=$m->consumed_generation)return 'Aguarde o contato sair e voltar ao segmento.';
  }elseif($person->replied_at)return 'Contato já está em atendimento; não há participação anterior autorizando reentrada.';
  if($p['mode']==='event'&&!$event)return 'Aguardando novo evento autorizado.';
  if($event&&$p['mode']!=='event')return 'Esta cadência não usa entrada por evento.';
  if($person->replied_at&&$previous&&CarbonImmutable::parse($person->replied_at)->gt($previous->ended_at))return 'Há uma resposta posterior ao encerramento; revise o atendimento.';
  return null;
 }
 public function enter(object $c,int $contact,?string $event=null,bool $fail=true):array {
  if($reason=$this->reason($c,$contact,$event)){if($fail)abort(422,$reason);return ['eligible'=>false,'reason'=>$reason];}
  $p=$this->policy($c);$r=$this->create($c,DB::table('voice_contacts')->find($contact),$p,$event?'event':$p['mode'],$event);
  DB::table('voice_cadence_membership')->where('campaign_id',$c->id)->where('contact_id',$contact)->update(['consumed_generation'=>DB::raw('generation')]);
  return ['eligible'=>true,'run_id'=>$r->id,'participation'=>$r->number];
 }
 public function event(int $w,int $campaign,int $contact,string $key,bool $preview=false):array {
  return DB::transaction(function()use($w,$campaign,$contact,$key,$preview){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$c=DB::table('voice_campaigns')->where('workspace_id',$w)->find($campaign);abort_unless($c,404);
   $hash=hash('sha256',$campaign.':'.$contact);$old=DB::table('voice_cadence_events')->where('campaign_id',$campaign)->where('event_key',$key)->first();if($old){abort_unless(hash_equals($old->request_hash,$hash),409,'Evento já utilizado para outro contato.');return ['duplicate'=>true]+json_decode($old->result,true);}
   if($preview){DB::beginTransaction();try{$this->sync($c,false);$reason=$this->reason($c,$contact,$key);return ['eligible'=>!$reason,'reason'=>$reason,'preview'=>true];}finally{DB::rollBack();}}
   $this->sync($c,false);$reason=$this->reason($c,$contact,$key);
   $result=$reason?['eligible'=>false,'reason'=>$reason]:$this->enter($c,$contact,$key);
   DB::table('voice_cadence_events')->insert(['campaign_id'=>$campaign,'contact_id'=>$contact,'event_key'=>$key,'request_hash'=>$hash,'result'=>json_encode($result),'created_at'=>now(),'updated_at'=>now()]);return ['duplicate'=>false]+$result;
  });
 }

 public function membershipChanged(string $kind,int $list,array $ids,bool $present):void {
  $campaigns=DB::table('voice_campaigns as c')->join('voice_campaign_policies as p','p.campaign_id','=','c.id')->where($kind==='voice'?'p.list_id':'p.audience_id',$list)->select('c.*')->get();
  if($kind==='automation')$ids=DB::table('voice_audience_contacts')->where('audience_id',$list)->whereIn('contact_id',$ids)->pluck('voice_contact_id')->all();
  foreach($campaigns as $c){if(!$this->policy($c))continue;foreach($ids as $id){
   $m=DB::table('voice_cadence_membership')->where('campaign_id',$c->id)->where('contact_id',$id)->first();if(!$m)continue;
   if((bool)$m->present!==$present)DB::table('voice_cadence_membership')->where('campaign_id',$c->id)->where('contact_id',$id)->update(['present'=>$present,'generation'=>$m->generation+($present?1:0),'updated_at'=>now()]);
   if(!$present&&($run=$this->current($c->id,$id)))$this->finish($run,'source_left');
  }}
 }
 public function tick():void {DB::transaction(function(){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();foreach(DB::table('voice_campaigns')->get()as $c)if($this->policy($c))$this->sync($c);});}
}
