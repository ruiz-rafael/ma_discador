<?php
namespace App\Services;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
class SpeechStudio {
 public const VOICES=['pf_dora'=>'Dora · feminino','pm_alex'=>'Alex · masculino','pm_santa'=>'Santa · masculino'];
 public const LANGUAGES=['pt-BR'=>'Português · Brasil','en-US'=>'Inglês · Estados Unidos','es-MX'=>'Espanhol · América Latina'];
 public const VOICE_LANGUAGES=['pf_dora'=>'pt-BR','pm_alex'=>'pt-BR','pm_santa'=>'pt-BR','af_heart'=>'en-US','af_bella'=>'en-US','am_michael'=>'en-US','ef_dora'=>'es-MX','em_alex'=>'es-MX','br_reference_f'=>'pt-BR','us_reference_f'=>'en-US','latam_reference_f'=>'es-MX'];
 public const ENGINES=[
  'kokoro'=>['name'=>'Kokoro','description'=>'Leve e rápido · português, inglês e espanhol','voices'=>self::VOICES+['af_heart'=>'Heart · feminino','af_bella'=>'Bella · feminino','am_michael'=>'Michael · masculino','ef_dora'=>'Dora · espanhol geral','em_alex'=>'Alex · espanhol geral'],'max_chars'=>1500,'model'=>'kokoro-v1.0-onnx'],
  'chatterbox'=>['name'=>'Chatterbox','description'=>'Voz expressiva · modelos por idioma · geração mais demorada','voices'=>['br_reference_f'=>'Brasileira · referência feminina','us_reference_f'=>'Inglês · referência feminina','latam_reference_f'=>'Latino-americana · referência feminina'],'max_chars'=>300,'model'=>'chatterbox-v3-pt-en-es-20261015-int8'],
 ];
 private function engine(array $d):string {$engine=$d['engine']??'kokoro';abort_unless(isset(self::ENGINES[$engine])&&isset(self::ENGINES[$engine]['voices'][$d['voice']]),422,'Escolha uma voz disponível para este motor.');abort_unless(($d['language']??'pt-BR')===(self::VOICE_LANGUAGES[$d['voice']]??null),422,'Escolha uma voz compatível com o idioma.');return $engine;}
 public function catalog():array {$engines=[];foreach(self::ENGINES as $id=>$e)$engines[$id]=array_diff_key($e,['model'=>true])+['service'=>$this->health($id),'voice_languages'=>array_intersect_key(self::VOICE_LANGUAGES,$e['voices'])];return $engines;}
 public function root():string{return app()->environment('testing')?config('speech_test.root',storage_path('framework/testing/speech')):storage_path('app/private/speech');}
 private function config(string $engine='kokoro'):array {
  $c=app()->environment('testing')?config('speech_test'): (is_readable($p=storage_path('app/private/voice/speech.json'))?json_decode(file_get_contents($p),true):null);
  abort_unless($c&&!empty($c['token']),503,'O serviço de voz ainda não está disponível.');$c['url']=$engine==='chatterbox'?($c['chatterbox_url']??null):($c['url']??null);abort_unless($c['url'],503,'Este motor de voz ainda não está disponível.');return $c;
 }
 public function health(string $engine='kokoro'):array {try{$c=$this->config($engine);$r=Http::withToken($c['token'])->connectTimeout(2)->timeout(3)->withoutRedirecting()->get($c['url'].'/health');return ['ready'=>$r->successful()&&$r->json('ready')===true,'busy'=>(bool)$r->json('busy'),'engine'=>self::ENGINES[$engine]['name']];}catch(\Throwable){return ['ready'=>false,'busy'=>false,'engine'=>self::ENGINES[$engine]['name']];}}
 public function fields(string $body):array {
  preg_match_all('/\{([a-z][a-z0-9_]{0,39})\}/u',$body,$m);$rest=preg_replace('/\{([a-z][a-z0-9_]{0,39})\}/u','',$body);
  abort_if(str_contains($rest,'{')||str_contains($rest,'}'),422,'Use variáveis como {primeiro_nome}, com letras minúsculas e sublinhado.');
  $fields=array_values(array_unique($m[1]));abort_if(count($fields)>12,422,'Use no máximo 12 variáveis.');return $fields;
 }
 public function render(string $body,array $values):string {
  foreach($this->fields($body)as $key)abort_unless(isset($values[$key])&&is_string($values[$key])&&trim($values[$key])!==''&&mb_strlen($values[$key])<=300,422,'Preencha a variável {'.$key.'}.');
  $text=preg_replace_callback('/\{([a-z][a-z0-9_]{0,39})\}/u',fn($m)=>trim($values[$m[1]]),$body);
  abort_if(mb_strlen($text)>1500,422,'O texto preenchido ultrapassa 1.500 caracteres.');
  return $text;
 }
 public function pronounce(string $text,array $rules):string {
  abort_if(count($rules)>20,422,'Use até 20 ajustes de pronúncia.');$map=[];
  foreach($rules as $rule){$term=trim($rule['term']??'');$spoken=trim($rule['spoken']??'');abort_if($term===''||$spoken===''||mb_strlen($term)>80||mb_strlen($spoken)>160||preg_match('/[{}<>]/u',$term.$spoken),422,'Preencha o termo e sua pronúncia usando texto simples.');$key=mb_strtolower($term);abort_if(isset($map[$key]),422,'Há termos repetidos no ajuste de pronúncia.');$map[$key]=$spoken;}
  if(!$map)return $text;$terms=array_keys($map);usort($terms,fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
  return preg_replace_callback('/(?<![\\p{L}\\p{N}_])(?:'.implode('|',array_map(fn($t)=>preg_quote($t,'/'),$terms)).')(?![\\p{L}\\p{N}_])/iu',fn($m)=>$map[mb_strtolower($m[0])],$text);
 }
 public function present(object $record):object {$record->pronunciations=json_decode($record->pronunciations??'[]',true)??[];return $record;}
 public function save(int $w,array $d,?string $id):object {
  $engine=$this->engine($d);$this->fields($d['body']);$this->pronounce($d['body'],$d['pronunciations']??[]);return DB::transaction(function()use($w,$d,$id,$engine){
   $old=$id?DB::table('speech_templates')->where('workspace_id',$w)->where('id',$id)->lockForUpdate()->firstOrFail():null;
   if($old)abort_unless($old->revision===($d['revision']??null),409,'Este template foi alterado. Atualize antes de salvar.');
   $id??=(string)Str::uuid();$v=['language'=>$d['language']??'pt-BR','pronunciations'=>json_encode($d['pronunciations']??[]),'engine'=>$engine,'name'=>$d['name'],'body'=>$d['body'],'voice'=>$d['voice'],'speed'=>$d['speed'],'revision'=>($old->revision??0)+1,'updated_at'=>now()];
   if($old)DB::table('speech_templates')->where('id',$id)->update($v);else DB::table('speech_templates')->insert($v+['id'=>$id,'workspace_id'=>$w,'created_at'=>now()]);
   return $this->present(DB::table('speech_templates')->find($id));
  });
 }
 public function generate(int $w,int $u,array $d):object {
  $engine=$this->engine($d);$original=$this->render($d['body'],$d['values']??[]);$text=$this->pronounce($original,$d['pronunciations']??[]);$language=$d['language']??'pt-BR';abort_if(mb_strlen($text)>self::ENGINES[$engine]['max_chars'],422,'O texto preenchido para '.self::ENGINES[$engine]['name'].' deve ter até '.self::ENGINES[$engine]['max_chars'].' caracteres.');$hash=hash('sha256',json_encode([$w,$original,$text,$language,$d['voice'],(float)$d['speed'],self::ENGINES[$engine]['model']]));
  return DB::transaction(function()use($w,$u,$d,$text,$original,$language,$hash,$engine){
   DB::table('voice_workspaces')->where('id',$w)->lockForUpdate()->firstOrFail();
   $old=DB::table('speech_assets')->where('workspace_id',$w)->where('fingerprint',$hash)->whereIn('status',['generating','ready'])->where('expires_at','>',now()->addHour())->orderByDesc('created_at')->first();
   if($old){$old=$this->refresh($old);if(in_array($old->status,['generating','ready']))return $old;}
   abort_if(DB::table('speech_assets')->where('workspace_id',$w)->where('created_at','>=',now()->startOfDay())->count()>=100,429,'Limite de 100 gerações por dia atingido.');
   $c=$this->config($engine);$id=(string)Str::uuid();DB::table('speech_assets')->insert(['language'=>$language,'spoken_body'=>$text,'engine'=>$engine,'id'=>$id,'workspace_id'=>$w,'user_id'=>$u,'name'=>$d['name'],'body'=>$original,'voice'=>$d['voice'],'speed'=>$d['speed'],'fingerprint'=>$hash,'status'=>'generating','expires_at'=>now()->addDays(30),'created_at'=>now(),'updated_at'=>now()]);
   try{$r=Http::withToken($c['token'])->connectTimeout(2)->timeout(8)->withoutRedirecting()->post($c['url'].'/generate',['workspace_id'=>$w,'id'=>$id,'text'=>$text,'language'=>$language,'voice'=>$d['voice'],'speed'=>(float)$d['speed'],'fingerprint'=>$hash]);
    if(!$r->successful())DB::table('speech_assets')->where('id',$id)->update(['status'=>'failed','error'=>$r->status()===429?'Há outro áudio sendo gerado. Aguarde e tente novamente.':'O serviço de voz não confirmou a geração.']);
   }catch(\Throwable){DB::table('speech_assets')->where('id',$id)->update(['error'=>'Serviço sem resposta. Aguardando confirmação da geração.']);}
   return DB::table('speech_assets')->find($id);
  });
 }
 private function directory(object $a):string {abort_unless(Str::isUuid($a->id)&&(int)$a->workspace_id>0,404);return $this->root().(($a->engine??'kokoro')==='chatterbox'?'/chatterbox':'').'/'.$a->workspace_id.'/'.$a->id;}
 public function refresh(object $a):object {
  if(now()->gte($a->expires_at)){$a->status='expired';return $a;}
  $f=$this->directory($a).'/state.json';
  if($a->status==='generating'||$a->status==='failed'){
   $s=is_file($f)?json_decode(file_get_contents($f),true):null;
   if($s&&($s['fingerprint']??'')===$a->fingerprint&&in_array($s['status']??'', ['ready','failed'])){
    $v=$s['status']==='ready'?['status'=>'ready','duration'=>$s['duration'],'generation_seconds'=>$s['generation_seconds'],'error'=>null]:['status'=>'failed','error'=>'A geração foi interrompida. Você pode gerar novamente.'];
    DB::table('speech_assets')->where('id',$a->id)->update($v+['updated_at'=>now()]);$a=DB::table('speech_assets')->find($a->id);
   }elseif($a->status==='generating'&&now()->gt(\Carbon\CarbonImmutable::parse($a->created_at)->addMinutes(10))){DB::table('speech_assets')->where('id',$a->id)->update(['status'=>'failed','error'=>'Tempo de geração esgotado. Gere uma nova prévia.']);$a=DB::table('speech_assets')->find($a->id);}
  }
  return $a;
 }
 public function asset(int $w,string $id):object{return $this->refresh(DB::table('speech_assets')->where('workspace_id',$w)->where('id',$id)->firstOrFail());}
 public function file(int $w,string $id,string $format):string {
  abort_unless(in_array($format,['wav','mp3','ogg']),404);$a=$this->asset($w,$id);abort_unless($a->status==='ready',409,'Este áudio ainda não está pronto ou expirou.');$f=$this->directory($a).'/audio.'.$format;abort_unless(is_file($f)&&filesize($f)>44&&filesize($f)<12*1024*1024,404);return $f;
 }
 /** Short-lived delivery URLs are for provider adapters, never required by the private preview. */
 public function deliveryUrl(int $w,string $id,string $format):string {$this->file($w,$id,$format);return \Illuminate\Support\Facades\URL::temporarySignedRoute('speech.delivery',now()->addHour(),['workspace'=>$w,'id'=>$id,'format'=>$format]);}
 public function purge():int {
  $n=0;foreach(DB::table('speech_assets')->where('expires_at','<=',now())->where('status','!=','expired')->limit(100)->get()as $a){$dir=$this->directory($a);foreach(['state.json','audio.wav','audio.mp3','audio.ogg','original.wav']as $f)if(is_file($dir.'/'.$f))unlink($dir.'/'.$f);if(is_dir($dir))@rmdir($dir);DB::table('speech_assets')->where('id',$a->id)->update(['status'=>'expired','body'=>'','spoken_body'=>'','updated_at'=>now()]);$n++;}return $n;
 }
}
