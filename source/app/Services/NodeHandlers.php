<?php
namespace App\Services;
use App\Models\{Contact,JourneyToken,CrmRecord};
class NodeHandlers {
 public function matches(Contact $c,array $s):bool {
  $field=$s['field']??'';$value=match($field){'tags'=>$c->tags??[], 'score'=>$c->score,'stage'=>$c->stage,'email'=>$c->email,'phone'=>$c->phone,'name'=>$c->name,default=>data_get($c->fields,$field)};$target=$s['value']??null;
  return match($s['operator']??'eq'){'eq'=>$value==$target,'neq'=>$value!=$target,'gt'=>is_numeric($value)&&is_numeric($target)&&$value>$target,'lt'=>is_numeric($value)&&is_numeric($target)&&$value<$target,'contains'=>is_array($value)?in_array($target,$value):str_contains((string)$value,(string)$target),default=>false};
 }
 public function execute(JourneyToken $token,array $node):array {
  $s=$node['data']['settings']??[];$key=$node['data']['key'];$c=$token->subscriber->contact;$context=$token->context??[];
  if(NodeCatalog::all()[$key]['type']==='trigger'||$key==='merge')return ['port'=>'success'];
  switch($key){
   case 'if_else': return ['port'=>$this->matches($c,$s)?'yes':'no'];
   case 'multi_branch':foreach($s['branches'] as $i=>$rule)if($this->matches($c,$rule))return ['port'=>'branch_'.($i+1)];return ['port'=>'other'];
   case 'ab_split':return ['port'=>(hexdec(substr(hash('sha256',$token->subscriber->id.':'.$node['id']),0,8))%100)<$s['percentage']?'a':'b'];
   case 'delay':if(!isset($context['wait_started']))return ['wait'=>true,'minutes'=>(int)$s['minutes']];return ['port'=>'success'];
   case 'wait_until':if($this->matches($c,$s))return ['port'=>'yes'];if(isset($context['wait_started'])&&now()->greaterThanOrEqualTo($token->resume_at))return ['port'=>'timeout'];return ['wait'=>true,'minutes'=>(int)$s['minutes']];
   case 'check_activity':if(($context['activity']??null)===($s['event']??null))return ['port'=>'yes'];if(isset($context['wait_started']))return ['port'=>'timeout'];return ['wait'=>true,'minutes'=>(int)$s['minutes']];
   case 'add_tag':$c->tags=array_values(array_unique(array_merge($c->tags??[],[$s['tag']])));$c->save();break;
   case 'remove_tag':$c->tags=array_values(array_diff($c->tags??[],[$s['tag']]));$c->save();break;
   case 'update_field':if(in_array($s['field'],['name','email','phone','stage']))$c->{$s['field']}=$s['value'];elseif($s['field']==='score')$c->score=(int)$s['value'];elseif($s['field']==='tags')throw new \RuntimeException('Use as ações Atribuir tag ou Remover tag.');else $c->fields=array_merge($c->fields??[],[$s['field']=>$s['value']]);$c->save();break;
   case 'update_score':$c->score=(int)$s['score'];$c->save();break;
   case 'update_stage':$c->stage=$s['stage'];$c->save();break;
   case 'subscription':$c->subscribed=filter_var($s['subscribed'],FILTER_VALIDATE_BOOLEAN);$c->save();break;
   case 'add_list':if(($s['list_source']??'local')==='crm')return $this->crmList($token,$key,$s);$c->audiences()->syncWithoutDetaching([(int)$s['audience_id']]);break;
   case 'remove_list':if(($s['list_source']??'local')==='crm')return $this->crmList($token,$key,$s);$c->audiences()->detach((int)$s['audience_id']);break;
   case 'create_task':case 'create_deal':CrmRecord::create(['contact_id'=>$c->id,'type'=>$key==='create_task'?'task':'deal','title'=>$s['title'],'data'=>$s]);break;
   case 'remove_journey':return ['stop'=>true];
   case 'move_journey':app(JourneyExecutionEngine::class)->enroll(\App\Models\Journey::findOrFail($s['journey_id']),$c,'move:'.$token->id,'open_trigger');return ['stop'=>true];
   case 'webhook':$response=app(SafeHttp::class)->send($s['url'],$s['method'],$s['payload']??[],'ma-token-'.$token->id);return ['port'=>'success','response'=>$response];
   case 'send_email':case 'send_sms':case 'send_whatsapp':case 'internal_email':case 'sync_crm':
    if(in_array($key,['send_email','send_sms','send_whatsapp'])&&!$c->subscribed)throw new \RuntimeException('Contato sem assinatura ativa.');
    if(!config('marketing.gateway_url'))throw new \RuntimeException('Gateway de integração não configurado.');
    $response=app(SafeHttp::class)->send(config('marketing.gateway_url'),'POST',['action'=>$key,'contact'=>$c->toArray(),'settings'=>$s,'token_id'=>$token->id], 'ma-token-'.$token->id,config('marketing.gateway_token'));
    return ['port'=>$key==='send_whatsapp'?'sent':'success','response'=>$response];
   default:throw new \RuntimeException('Handler não disponível.');
  }
  return ['port'=>'success'];
 }
 private function crmList(JourneyToken $token,string $key,array $settings):array {
  if(!config('marketing.gateway_url'))throw new \RuntimeException('Lista do CRM aguarda conexão do módulo.');
  $response=app(SafeHttp::class)->send(config('marketing.gateway_url'),'POST',['action'=>$key,'contact'=>$token->subscriber->contact->toArray(),'settings'=>$settings,'token_id'=>$token->id],'ma-token-'.$token->id,config('marketing.gateway_token'));
  return ['port'=>'success','response'=>$response];
 }

}
