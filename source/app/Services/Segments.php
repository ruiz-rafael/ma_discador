<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
/** Rule membership is materialized, with explicit inclusions and durable exclusions. Never enrolls or contacts anyone. */
class Segments {
 private array $engaged=[];
 public function validate(int $w,array $d,array $fields):array {
  $keys=array_merge(ListContacts::BASE,array_map(fn($f)=>'fields.'.$f['key'],$fields),['cadence_engaged']);
  $v=Validator::make($d,['mode'=>'required|in:manual,rules','rule_match'=>'required|in:all,any','rules'=>'present|array|max:20','rules.*'=>'array:field,operator,value,campaign_id','rules.*.field'=>['required',Rule::in($keys)],'rules.*.operator'=>'required|in:eq,neq,contains,starts_with,gt,gte,lt,lte,set,not_set','rules.*.value'=>'nullable','rules.*.campaign_id'=>'nullable|integer|min:1'])->validate();
  abort_if($v['mode']==='rules'&&!$v['rules'],422,'Adicione ao menos uma regra ao segmento dinâmico.');
  foreach($v['rules']as $rule){
   $f=collect($fields)->first(fn($f)=>'fields.'.$f['key']===$rule['field']);$type=$rule['field']==='consent'||$rule['field']==='cadence_engaged'?'boolean':($f['type']??'text');$op=$rule['operator'];
   if($rule['field']==='cadence_engaged')abort_unless(DB::table('voice_campaigns')->where('workspace_id',$w)->where('id',$rule['campaign_id']??0)->exists(),422,'Selecione a cadência da regra de atendimento/resposta.');
   if(in_array($op,['set','not_set']))continue;
   $value=$rule['value']??null;abort_if($value===null,422,'Preencha o valor da regra.');
   if($type==='boolean')abort_unless(is_bool($value)&&in_array($op,['eq','neq']),422,'Use sim/não e igual/diferente para campos booleanos.');
   elseif($type==='number')Validator::make(['value'=>$value],['value'=>'required|numeric|between:-1000000000000,1000000000000'])->validate();
   elseif($type==='date')Validator::make(['value'=>$value],['value'=>'required|date_format:Y-m-d'])->validate();
   else {abort_unless(is_string($value)&&mb_strlen($value)<=2000,422,'Informe um texto de até 2.000 caracteres.');abort_if(in_array($op,['gt','gte','lt','lte']),422,'Comparações de ordem exigem campo numérico ou data.');}
   if(in_array($type,['number','date']))abort_if(in_array($op,['contains','starts_with']),422,'Use comparações de número ou data para este campo.');
  }
  return $v;
 }
 public function pin(int $w,string $kind,int $list,int $contact):void {DB::table('ma_segment_pins')->insertOrIgnore(['workspace_id'=>$w,'kind'=>$kind,'list_id'=>$list,'contact_id'=>$contact,'created_at'=>now()]);}
 private function value(int $w,string $kind,object $c,array $r):mixed {
  $fields=json_decode($c->fields??'{}',true)??[];$key=$r['field'];
  if($key==='cadence_engaged'){
   $cache=$w.':'.$r['campaign_id'];
   if(!isset($this->engaged[$cache])){
    $calls=DB::table('voice_outbound_calls as e')->join('voice_contacts as c','c.id','=','e.contact_id')->where('e.workspace_id',$w)->where('e.campaign_id',$r['campaign_id'])->where(fn($q)=>$q->whereNotNull('e.answered_at')->orWhere('e.status','completed'))->pluck('c.phone');
    $replies=DB::table('wa_messages as e')->join('voice_contacts as c','c.id','=','e.contact_id')->where('e.workspace_id',$w)->where('e.campaign_id',$r['campaign_id'])->where('e.direction','inbound')->pluck('c.phone');
    $this->engaged[$cache]=array_fill_keys($calls->merge($replies)->all(),true);
   }
   try{$phone=$c->phone?VoiceLab::phone($c->phone):'';}catch(\Throwable){$phone='';}
   return isset($this->engaged[$cache][$phone]);
  }
  if($key==='consent')return (bool)($kind==='voice'?$c->consent:$c->subscribed);
  if(str_starts_with($key,'fields.'))return $fields[substr($key,7)]??null;
  return $c->$key??$fields[$key]??null;
 }
 private function matches(int $w,string $kind,object $c,array $d,array $fields):bool {
  $results=[];foreach($d['rules']as $r){$actual=$this->value($w,$kind,$c,$r);$value=$r['value']??null;$op=$r['operator'];
   if($op==='set'){$results[]=$actual!==null&&$actual!=='';continue;}if($op==='not_set'){$results[]=$actual===null||$actual==='';continue;}
   if($actual===null){$results[]=false;continue;}
   $field=collect($fields)->first(fn($f)=>'fields.'.$f['key']===$r['field']);
   if(($field['type']??'')==='number'){if(!is_numeric($actual)){$results[]=false;continue;}$actual=(float)$actual;$value=(float)$value;}
   elseif(is_bool($value)){$actual=filter_var($actual,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);}
   else {if(!is_scalar($actual)){$results[]=false;continue;}$actual=mb_strtolower((string)$actual);$value=mb_strtolower((string)$value);}
   $results[]=match($op){'eq'=>$actual===$value,'neq'=>$actual!==$value,'contains'=>str_contains($actual,$value),'starts_with'=>str_starts_with($actual,$value),'gt'=>$actual>$value,'gte'=>$actual>=$value,'lt'=>$actual<$value,'lte'=>$actual<=$value,default=>false};
  }return $d['rule_match']==='any'?in_array(true,$results,true):!in_array(false,$results,true);
 }
 public function preview(int $w,string $kind,int $list,array $d):array {
  $this->engaged=[];$s=app(ListContacts::class);$s->list($w,$kind,$list);$fields=$s->settings($w,$kind,$list)['fields'];$d=$this->validate($w,$d,$fields);
  $q=DB::table($kind==='voice'?'voice_contacts':'contacts');if($kind==='voice')$q->where('workspace_id',$w);
  abort_if($q->count()>5000,422,'O avaliador atual aceita até 5.000 contatos por cadastro.');
  $pins=DB::table('ma_segment_pins')->where('workspace_id',$w)->where('kind',$kind)->where('list_id',$list)->pluck('contact_id')->all();
  $excluded=$kind==='voice'?DB::table('voice_list_members')->where('list_id',$list)->where('status','removed')->pluck('contact_id')->all():DB::table('ma_list_membership_exclusions')->where('audience_id',$list)->pluck('contact_id')->all();
  $ids=[];$sample=[];$matched=0;$pinned=0;foreach($q->orderBy('id')->cursor()as $c){if(in_array($c->id,$excluded))continue;$pin=in_array($c->id,$pins);$match=$d['mode']==='rules'&&$this->matches($w,$kind,$c,$d,$fields);if($d['mode']==='rules'?!$match:!$pin)continue;$ids[]=$c->id;$matched+=(int)$match;$pinned+=(int)$pin;if(count($sample)<20)$sample[]=['id'=>$c->id,'name'=>$c->name,'phone'=>$c->phone,'reason'=>$d['mode']==='rules'?'Corresponde às regras':'Inclusão explícita'];}
  return ['total'=>count($ids),'matched'=>$matched,'pinned'=>$pinned,'sample'=>$sample,'ids'=>$ids];
 }
 public function eligible(int $w,string $kind,int $list,object $contact):bool {
  $d=app(ListContacts::class)->settings($w,$kind,$list);if($d['mode']==='manual')return true;
  $this->engaged=[];return $this->matches($w,$kind,$contact,$d,$d['fields']);
 }
 public function refresh(int $w,string $kind,int $list):void {
  $setting=app(ListContacts::class)->settings($w,$kind,$list);if(($setting['mode']??'manual')!=='rules')return;
  DB::transaction(function()use($w,$kind,$list){app(ListContacts::class)->lock();$d=app(ListContacts::class)->settings($w,$kind,$list);if($d['mode']!=='rules')return;$ids=$this->preview($w,$kind,$list,$d)['ids'];
   if($kind==='automation'){
    $removed=DB::table('audience_contact')->where('audience_id',$list)->whereNotIn('contact_id',$ids)->pluck('contact_id');foreach($removed as $id)app(VoiceAudience::class)->removed($list,$id);
    DB::table('audience_contact')->where('audience_id',$list)->whereNotIn('contact_id',$ids)->delete();foreach($ids as $id)DB::table('audience_contact')->insertOrIgnore(['audience_id'=>$list,'contact_id'=>$id]);
   }else {
    $removed=DB::table('voice_list_members')->where('list_id',$list)->where('status','active')->whereNotIn('contact_id',$ids)->pluck('contact_id');
    DB::table('voice_followups')->whereIn('campaign_id',DB::table('voice_campaign_policies')->where('list_id',$list)->select('campaign_id'))->whereIn('contact_id',$removed)->whereIn('status',['pending','blocked'])->update(['status'=>'cancelled','reason'=>'Contato deixou as regras do segmento.','updated_at'=>now()]);
    DB::table('voice_list_members')->where('list_id',$list)->where('status','active')->whereNotIn('contact_id',$ids)->update(['status'=>'outside_rules','reason'=>'Fora das regras do segmento','updated_at'=>now()]);
    foreach($ids as $id){DB::table('voice_list_members')->where('list_id',$list)->where('contact_id',$id)->where('status','outside_rules')->update(['status'=>'active','reason'=>null,'updated_at'=>now()]);app(ListContacts::class)->link($w,$kind,$list,$id);}
   }
  });
 }
 public function refreshAll():void {foreach(DB::table('ma_list_settings')->where('mode','rules')->get()as $s)$this->refresh($s->workspace_id,$s->kind,$s->list_id);}
}
