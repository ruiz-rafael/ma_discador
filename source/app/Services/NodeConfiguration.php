<?php
namespace App\Services;
use App\Contracts\CrmCatalog;
use App\Models\{Audience,Journey};
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
class NodeConfiguration {
 public static function schemas():array {static $schemas;return $schemas??=json_decode(file_get_contents(config_path('node-schemas.json')),true,512,JSON_THROW_ON_ERROR);}
 public static function schema(string $key):array{return self::schemas()[$key]??['fields'=>[],'description'=>''];}
 public static function applicable(array $field,array $settings):bool {foreach($field['when']??[] as $key=>$value)if(($settings[$key]??null)!==$value)return false;return true;}
 public function errors(string $key,array $settings,bool $complete=true,bool $activation=false):array {
  $issues=[];$schema=self::schema($key);$rules=['_config_version'=>'sometimes|integer|in:2','_labels'=>'sometimes|array','_labels.*'=>'string|max:200'];
  $normalized=$settings;foreach($schema['fields']as $f)if(!array_key_exists($f['key'],$normalized)&&array_key_exists('default',$f))$normalized[$f['key']]=$f['default'];
  foreach($schema['fields']as $f){if(!self::applicable($f,$normalized))continue;$name=$f['key'];$value=$normalized[$name]??null;$present=array_key_exists($name,$normalized);$rule=[$complete&&$f['required']?'required':'nullable'];
   $type=$f['type'];$rule=array_merge($rule,match($type){'number'=>['numeric','min:'.($f['min']??0),'max:'.($f['max']??1000000000)],'duration'=>['integer','min:1','max:525600'],'boolean'=>['boolean'],'select'=>['string',Rule::in(array_column($f['options'],'value'))],'local_list','journey'=>['integer','min:1'],'rules','pairs','buttons','json'=>['array','max:'.($type==='buttons'?8:($type==='rules'?2:50))],default=>['string','max:'.($name==='url'?2000:500)]});
   if(($f['format']??null)==='email')$rule[]='email';if(in_array($f['format']??null,['url','https']))$rule[]='url:http,https';$rules[$name]=$rule;
   if($type==='buttons')$rules['buttons.*']=['string','max:60','distinct','regex:/^[a-zA-Z0-9_:-]+$/',Rule::notIn(['sent','delivered','read','failed'])];
   if($type==='pairs'){$rules[$name.'.*']=['string','max:1000'];}
   if($type==='rules'){$rules['branches'][]='size:2';$rules['branches.*.field']='required|string|max:160';$rules['branches.*.operator']=['required',Rule::in(['eq','neq','gt','lt','contains'])];$rules['branches.*.value']='required';}
   if($complete&&$type==='resource'&&is_string($value)&&$value!==''){
    // Legacy free-text templates remain readable; new forms always use catalog references.
    if($name==='template'&&!isset($settings['_config_version']))continue;
    $item=app(CrmCatalog::class)->find($f['resource'],$value);$channel=$f['channel']??($normalized[$f['channel_field']??'']??null);
    if(!$item||!$item['active'])$issues[]=$f['label'].': referência ausente ou inativa no catálogo do CRM.';
    elseif($channel&&$item['channel']!==$channel)$issues[]=$f['label'].': o canal não corresponde ao nó.';
    elseif($f['resource']==='template'&&!empty($item['metadata']['campaign_id'])&&($normalized['campaign_id']??null)!==$item['metadata']['campaign_id'])$issues[]='O template pertence a outra campanha.';
    if($item&&$f['resource']==='template')foreach($item['metadata']['parameters']??[] as $parameter)if(!isset($normalized['parameters'][$parameter])||trim((string)$normalized['parameters'][$parameter])==='')$issues[]='Preencha o parâmetro do template: '.$parameter.'.';
   }
   if($complete&&$value&&$type==='local_list'&&!Audience::whereKey($value)->exists())$issues[]='Lista local não encontrada.';
   if($complete&&$value&&$type==='journey'&&!Journey::whereKey($value)->exists())$issues[]='Jornada de destino não encontrada.';
  }
  $attributes=[];foreach($schema['fields']as $f)$attributes[$f['key']]=$f['label'];$messages=['required'=>':attribute é obrigatório.','string'=>':attribute deve ser um texto.','array'=>':attribute deve ter uma estrutura válida.','integer'=>':attribute deve ser um número inteiro.','numeric'=>':attribute deve ser um número.','boolean'=>':attribute deve ser Sim ou Não.','in'=>':attribute contém uma opção inválida.','not_in'=>':attribute usa um identificador reservado.','distinct'=>':attribute está duplicado.','regex'=>':attribute deve usar letras, números, sublinhado, dois-pontos ou hífen.','url'=>':attribute deve ser uma URL válida.','email'=>':attribute deve ser um e-mail válido.','size.array'=>':attribute deve ter exatamente :size itens.','max.array'=>':attribute aceita até :max itens.','max.string'=>':attribute aceita até :max caracteres.','max.numeric'=>':attribute deve ser no máximo :max.','min.numeric'=>':attribute deve ser no mínimo :min.'];$v=Validator::make($normalized,$rules,$messages,$attributes);foreach($v->errors()->all()as $error)$issues[]=$error;
  if($complete&&$key==='webhook'&&!str_starts_with($normalized['url']??'','https://'))$issues[]='O webhook exige uma URL HTTPS.';
  if($activation)$issues=array_merge($issues,$this->activationPending($key,$normalized));
  return $issues;
 }
 public function activationPending(string $key,array $settings):array {
  $pending=[];foreach(self::schema($key)['fields']as $f){if(!self::applicable($f,$settings)||$f['type']!=='resource'||empty($settings[$f['key']])||!is_string($settings[$f['key']]))continue;if($f['key']==='template'&&!isset($settings['_config_version']))continue;$item=app(CrmCatalog::class)->find($f['resource'],$settings[$f['key']]);if($item&&$item['origin']!=='crm')$pending[]=$f['label'].': referência preparada; aguarda confirmação pelo CRM.';}
  if((in_array($key,['send_email','send_sms','send_whatsapp','internal_email','sync_crm'])||(in_array($key,['add_list','remove_list'])&&($settings['list_source']??'local')==='crm'))&&!config('marketing.gateway_url'))$pending[]='A execução desta ação será habilitada quando o CRM estiver conectado.';
  return array_values(array_unique($pending));
 }
}
