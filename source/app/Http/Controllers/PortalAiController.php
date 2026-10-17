<?php
namespace App\Http\Controllers;
use App\Services\Portal\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Crypt,Http};

class PortalAiController extends Controller {
 private function operator(Request $r):void {abort_unless(app(Access::class)->operator($r->user()->id),403);}
 public function index(Request $r){$this->operator($r);return view('portal.ai-admin',['tenants'=>DB::table('portal_tenants')->orderBy('name')->get()]);}
 private function tenant(int $id):object {return DB::table('portal_tenants')->where('id',$id)->where('status','active')->firstOrFail();}
 private function call(object $t,int $actor,string $action,array $data=[]):array {
  abort_unless($t->url&&$t->secret&&str_starts_with($t->url,'https://'),409,'O ambiente ainda não está disponível.');
  $path='discai-internal/ai';$stamp=(string)time();$body=json_encode(['tenant'=>$t->slug,'actor'=>$actor,'action'=>$action]+$data,JSON_UNESCAPED_SLASHES);
  try{$r=Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(80)->withHeaders(['X-Discai-Time'=>$stamp,'X-Discai-Signature'=>hash_hmac('sha256',$stamp.'.'.$path.'.'.$body,Crypt::decryptString($t->secret))])->withBody($body,'application/json')->post($t->url.'/'.$path);}catch(\Throwable){abort(503,'O ambiente não confirmou a operação. Atualize antes de repetir.');}
  if(!$r->successful()){
   $message=$r->json('message');$errors=$r->json('errors');
   if(is_array($errors))$message=implode(' ',array_merge(...array_values($errors)));
   abort(in_array($r->status(),[409,422,429],true)?$r->status():502,is_string($message)?mb_substr($message,0,600):'O ambiente não confirmou a operação. Atualize antes de repetir.');
  }
  abort_unless(is_array($r->json()),502,'Resposta inválida do ambiente.');return $r->json();
 }
 public function show(Request $r,int $id){$this->operator($r);$tenant=$this->tenant($id);$settings=$this->call($tenant,$r->user()->id,'read');return view('portal.ai-settings',compact('tenant','settings'));}
 public function update(Request $r,int $id){
  $this->operator($r);$tenant=$this->tenant($id);$r->validate(['action'=>'required|in:save,probe']);
  try{
   if($r->action==='probe'){$result=$this->call($tenant,$r->user()->id,'probe');return back()->with('notice',$result['message']);}
   $d=$r->validate(['local'=>'required|array','openai'=>'required|array']);
   $this->call($tenant,$r->user()->id,'save',$d);
   app(Access::class)->audit('ai.settings.updated',$r->user()->id,$tenant->id,['text_provider'=>$d['local']['text_provider']??null]);
   return back()->with('notice','Parâmetros salvos. Nenhum canal foi ativado e nenhuma chamada ou mensagem foi enviada.');
  }catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){return back()->withErrors(['operation'=>$e->getMessage()?:'Não foi possível confirmar. Atualize antes de repetir.'])->withInput($r->except(['local.api_key','openai.api_key','openai.sip_secret']));}
 }
}
