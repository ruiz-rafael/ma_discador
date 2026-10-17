<?php
namespace App\Services;

use Illuminate\Support\Facades\{Crypt,DB,Http,Validator};

/** Ollama on infrastructure controlled by the platform administrator. No cloud fallback. */
class AiLocalProvider
{
 public function row(int $w):?object {return DB::table('ai_local_connections')->where('workspace_id',$w)->first();}
 public function provider(int $w):string {return $this->row($w)?->text_provider??'openai';}
 public function settings(int $w):array {
  $r=$this->row($w);
  return ['text_provider'=>$r?->text_provider??'openai','endpoint'=>$r?->endpoint??'',
   'key_configured'=>(bool)$r?->credentials,'model'=>$r?->model??'qwen3:8b','enabled'=>(bool)$r?->enabled,
   'daily_requests'=>$r?->daily_requests??100,'context_tokens'=>$r?->context_tokens??8192,
   'parallel_requests'=>$r?->parallel_requests??1,'timeout_seconds'=>$r?->timeout_seconds??35,
   'revision'=>$r?->revision??0,'verified_at'=>$r?->verified_at];
 }
 public function save(int $w,array $input):array {
  $d=Validator::make($input,[
   'text_provider'=>'required|in:openai,qwen','endpoint'=>'nullable|string|max:250',
   'api_key'=>'nullable|string|min:16|max:500','model'=>'required|in:qwen3:8b,qwen3:8b-q4_K_M',
   'enabled'=>'required|boolean','daily_requests'=>'required|integer|between:1,1000',
   'context_tokens'=>'required|integer|in:8192,16384','parallel_requests'=>'required|integer|between:1,4',
   'timeout_seconds'=>'required|integer|between:10,60','revision'=>'required|integer|min:0',
  ])->validate();
  foreach(['revision','daily_requests','context_tokens','parallel_requests','timeout_seconds']as $field)$d[$field]=(int)$d[$field];$d['enabled']=(bool)$d['enabled'];
  $d['endpoint']=rtrim($d['endpoint']??'','/');
  if($d['endpoint'])$this->target($d['endpoint']);
  $old=$this->row($w);$secret=!empty($d['api_key'])?Crypt::encryptString($d['api_key']):$old?->credentials;
  abort_if($old?->credentials&&$old->endpoint!==$d['endpoint']&&!empty($d['enabled'])&&empty($d['api_key']),422,'Informe a chave do novo servidor ao trocar o endereço.');
  if($old&&$old->endpoint!==$d['endpoint']&&empty($d['api_key']))$secret=null;
  $candidate=(object)($d+['credentials'=>$secret]);
  if($d['enabled']){abort_unless($d['endpoint']&&$secret,422,'Informe o servidor e a chave de acesso antes de habilitar.');$this->probeRow($candidate);}
  return DB::transaction(function()use($w,$d,$secret){
   DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$old=$this->row($w);
   abort_unless(($old?->revision??0)===$d['revision'],409,'A configuração mudou. Atualize antes de salvar.');
   // A disable is always allowed. Changing routing while a conversation is alive is not.
   $changed=($old?->text_provider??'openai')!==$d['text_provider']||($old?->endpoint??'')!==$d['endpoint']||($old?->model??'qwen3:8b')!==$d['model']||!empty($d['api_key']);
   abort_if($changed&&DB::table('ai_sessions')->where('workspace_id',$w)->where('mode','premium')->whereIn('channel',['text','whatsapp'])->where('status','open')->where('expires_at','>',now())->exists(),409,'Encerre as conversas de texto em andamento antes de trocar o provedor ou servidor.');
   unset($d['api_key']);$d['credentials']=$secret;$d['revision']=($old?->revision??0)+1;
   $d['verified_at']=$d['enabled']?now():null;$d['updated_at']=now();if(!$old)$d['created_at']=now();
   DB::table('ai_local_connections')->updateOrInsert(['workspace_id'=>$w],$d);return $this->settings($w);
  });
 }
 /** Reject URL credentials, metadata, redirects and untrusted private destinations; pin public DNS. */
 public function target(string $url):array {
  $p=parse_url($url);abort_unless($p&&isset($p['host'],$p['scheme'])&&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['query'])&&!isset($p['fragment'])&&empty($p['path']),422,'Use somente a origem do servidor, sem caminho, parâmetros ou credenciais na URL.');
  $trusted=in_array($url,config('ai_local.trusted_origins',[]),true);
  if($trusted){abort_unless(in_array($p['scheme'],['http','https'],true),422);return [];}
  abort_unless($p['scheme']==='https'&&($p['port']??443)===443,422,'Use HTTPS na porta 443. Servidores privados exigem liberação pela infraestrutura.');
  $host=$p['host'];$ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:gethostbynamel($host);
  abort_unless($ips,422,'Não foi possível resolver o servidor de IA.');
  foreach($ips as $ip)abort_unless(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE),422,'O endereço não é um servidor público permitido.');
  return [CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]]];
 }
 private function request(object $r){
  $curl=$this->target($r->endpoint);
  $http=Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout($r->timeout_seconds)->withOptions(['curl'=>$curl]);
  return $r->credentials?$http->withToken(Crypt::decryptString($r->credentials)):$http;
 }
 private function probeRow(object $r):void {
  try{$response=$this->request($r)->get($r->endpoint.'/api/tags');}catch(\Throwable){abort(502,'Não foi possível conectar ao servidor Qwen. Confira endereço, autenticação e disponibilidade.');}
  abort_unless($response->successful()&&collect($response->json('models')??[])->contains(fn($m)=>($m['name']??'')===$r->model),422,'O servidor não confirmou o modelo escolhido. Instale-o no Ollama antes de habilitar.');
 }
 public function probe(int $w):array {$r=$this->row($w);abort_unless($r?->endpoint,422,'Salve o endereço do servidor primeiro.');$this->probeRow($r);return ['ok'=>true,'message'=>'Servidor acessível e modelo instalado. Nenhum texto foi gerado; qualidade e velocidade ainda precisam ser testadas.'];}
 public function ready(int $w):object {$r=$this->row($w);abort_unless($r?->enabled&&$r->endpoint&&$r->credentials,409,'O atendimento escrito está aguardando liberação pela administração DiscAI.');return $r;}
 public function text(int $w,string $prompt,array $history):array {
  $c=$this->ready($w);$schema=AiProvider::schema();
  $prompt.="\nRetorne somente um objeto JSON com reply, action e summary, conforme o esquema: ".json_encode($schema);
  // Conservative byte bound: do not let Ollama silently truncate the mandatory instructions.
  $bytes=strlen($prompt)+array_sum(array_map(fn($m)=>strlen($m['content']),$history))+count($history)*32+128;
  abort_if($bytes+700>$c->context_tokens,422,'A conversa excedeu a capacidade configurada. Encaminhe para a equipe; nenhuma instrução foi descartada.');
  try{$r=$this->request($c)->post($c->endpoint.'/api/chat',[
   'model'=>$c->model,'stream'=>false,'think'=>false,'keep_alive'=>'5m','format'=>$schema,
   'messages'=>array_merge([['role'=>'system','content'=>$prompt]],$history),
   'options'=>['num_ctx'=>$c->context_tokens,'num_predict'=>700,'temperature'=>0.2],
  ]);}catch(\Throwable){abort(502,'O servidor de IA não respondeu a tempo. Encaminhe para a equipe.');}
  abort_unless($r->successful()&&$r->json('done')===true&&$r->json('done_reason')==='stop',502,'O servidor de IA não concluiu a resposta. Encaminhe para a equipe.');
  $answer=AiProvider::answer($r->json('message.content'));
  return $answer+['usage'=>['input'=>max(0,(int)$r->json('prompt_eval_count')),'output'=>max(0,(int)$r->json('eval_count'))],'estimated_usd'=>0];
 }
}
