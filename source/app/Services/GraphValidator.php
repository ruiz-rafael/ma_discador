<?php
namespace App\Services;
use Illuminate\Validation\ValidationException;
class GraphValidator {
 public function validate(array $graph,bool $publish=false,bool $activation=true):void {
  $errors=[];$nodes=$graph['nodes']??[];$edges=$graph['edges']??[];$catalog=NodeCatalog::all();$ids=[];$triggers=[];$adj=[];
  if(count($nodes)>150||count($edges)>300)$errors[]='Limite: 150 nós e 300 conexões.';
  foreach($nodes as $node){
   $id=$node['id']??'';$key=$node['data']['key']??'';$settings=$node['data']['settings']??[];
   if(!$id||isset($ids[$id])||!isset($catalog[$key])){$errors[]='Nó inválido ou identificador duplicado.';continue;}
   $ids[$id]=$node;$adj[$id]=[];if($catalog[$key]['type']==='trigger')$triggers[]=$id;
   foreach(app(NodeConfiguration::class)->errors($key,$settings,$publish,$publish&&$activation)as $issue)$errors[]=$catalog[$key]['label'].': '.$issue;
   if($publish&&$activation&&$key==='webhook'){try{app(SafeHttp::class)->validate($settings['url']??'');}catch(\Throwable $e){$errors[]=$e->getMessage();}}

  }
  $seen=[];foreach($edges as $edge){$source=$edge['source']??'';$target=$edge['target']??'';$handle=$edge['sourceHandle']??'success';
   if(!isset($ids[$source],$ids[$target])){$errors[]='Conexão aponta para nó inexistente.';continue;}
   $key=$ids[$source]['data']['key'];$ports=$catalog[$key]['ports'];if($key==='send_whatsapp')$ports=array_merge($ports,(is_array($ids[$source]['data']['settings']['buttons']??null)?$ids[$source]['data']['settings']['buttons']:[]));
   if(!in_array($handle,$ports))$errors[]='Porta de saída inválida.';
   if(in_array($target,$triggers))$errors[]='Gatilhos não podem receber conexões.';
   $signature="$source:$target:$handle";if(isset($seen[$signature]))$errors[]='Conexão duplicada.';$seen[$signature]=true;$adj[$source][]=$target;
  }
  if($publish){
   if(!$triggers)$errors[]='Adicione pelo menos um gatilho.';
   $visited=[];$stack=$triggers;while($stack){$id=array_pop($stack);if(isset($visited[$id]))continue;$visited[$id]=true;foreach($adj[$id]??[] as $next)$stack[]=$next;}
   if(count($visited)!==count($ids))$errors[]='Existem nós desconectados dos gatilhos.';
   $color=[];$visit=function($id)use(&$visit,&$color,$adj,&$errors){if(($color[$id]??0)===1){$errors[]='Ciclos não são permitidos nesta versão. Use o gatilho cíclico.';return;}if(($color[$id]??0)===2)return;$color[$id]=1;foreach($adj[$id]??[] as $next)$visit($next);$color[$id]=2;};foreach(array_keys($ids)as $id)$visit($id);
  }
  if($errors)throw ValidationException::withMessages(['graph'=>array_values(array_unique($errors))]);
 }
}
