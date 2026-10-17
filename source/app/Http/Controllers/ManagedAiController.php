<?php
namespace App\Http\Controllers;
use App\Services\{AiLocalProvider,AiProvider,VoiceLab};
use App\Services\Portal\TenantBridge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManagedAiController extends Controller {
 public function receive(Request $r,AiLocalProvider $local,AiProvider $gpt){
  $b=app(TenantBridge::class)->config();$stamp=$r->header('X-Discai-Time','');
  abort_unless($b&&ctype_digit($stamp)&&abs(time()-(int)$stamp)<=30&&strlen($r->getContent())<=8000,401);
  abort_unless(hash_equals(hash_hmac('sha256',$stamp.'.'.$r->path().'.'.$r->getContent(),$b['secret']),$r->header('X-Discai-Signature','')),401);
  abort_unless($r->input('tenant')===$b['slug'],403);
  $d=$r->validate(['action'=>'required|in:read,save,probe','local'=>'sometimes|array','openai'=>'sometimes|array','actor'=>'required|integer|min:1']);
  if($d['action']==='probe')return $local->probe(1);
  if($d['action']==='save'){
   $o=validator($d['openai']??[],['api_key'=>'nullable|string|min:20|max:300','enabled'=>'required|boolean','daily_requests'=>'required|integer|between:1,1000','sip_secret'=>['nullable','string','max:300','regex:/^whsec_[A-Za-z0-9+\/=]+$/D'],'project_id'=>['nullable','string','regex:/^proj_[a-zA-Z0-9_-]{1,100}$/D'],'cost_acknowledged'=>'accepted_if:enabled,1,true'])->validate();
   DB::transaction(function()use($local,$gpt,$d,$o){
    $local->save(1,$d['local']??[]);
    if($gpt->connection(1)['configured']||!empty($o['api_key']))$gpt->save(1,$o);
    else abort_if($o['enabled'],422,'Informe a chave OpenAI antes de habilitar.');
    app(VoiceLab::class)->audit(1,null,'ai.managed.settings','platform',['portal_actor'=>$d['actor'],'text_provider'=>$d['local']['text_provider'],'qwen_enabled'=>$d['local']['enabled'],'openai_enabled'=>$o['enabled']]);
   });
  }
  return response()->json(['local'=>$local->settings(1),'openai'=>$gpt->connection(1)])->header('Cache-Control','no-store, private');
 }
}
