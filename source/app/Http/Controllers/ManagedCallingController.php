<?php
namespace App\Http\Controllers;
use App\Services\{AdministrativeCalling,VoiceCalling,TwilioVoiceCalling};
use App\Services\Portal\TenantBridge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class ManagedCallingController extends Controller {
 private function reply(array $data){return response()->json($data)->header('Cache-Control','no-store, private');}
 public function receive(Request $r,TenantBridge $bridge,AdministrativeCalling $calling){
  $cfg=$bridge->config();$stamp=$r->header('X-Discai-Time','');
  abort_unless($cfg&&ctype_digit($stamp)&&abs(time()-(int)$stamp)<=30&&strlen($r->getContent())<=10000,401);
  abort_unless(hash_equals(hash_hmac('sha256',$stamp.'.'.$r->path().'.'.$r->getContent(),$cfg['secret']),$r->header('X-Discai-Signature','')),401);
  abort_unless($r->input('tenant')===$cfg['slug'],403);
  $d=$r->validate(['operator'=>'required|integer|min:1','action'=>'required|in:catalog,reserve,status,cancel,reconcile','request_id'=>'required_unless:action,catalog|uuid',
   'origin'=>'required_if:action,reserve|regex:/^\+[1-9][0-9]{7,14}$/D','method'=>'required_if:action,reserve|in:sip_trunk,programmable_voice',
   'destination'=>'required_if:action,reserve|string|max:30','consent_confirmed'=>'exclude_unless:action,reserve|required|accepted','consent_evidence'=>'required_if:action,reserve|string|min:8|max:1000']);
  // Recheck central role on every request, including requests signed by a tenant secret.
  $identity=$bridge->call('check',['user_id'=>$d['operator']]);abort_unless(($identity['platform_admin']??false)===true,403);
  if($d['action']==='catalog')return $this->reply(['tenant'=>$cfg['slug'],'numbers'=>$calling->catalog()]);
  if($d['action']==='reserve'){
   $calling->origin($d['method'],$d['origin']);
   $u=DB::transaction(function()use($identity){$u=User::where('email',$identity['email'])->lockForUpdate()->first();if(!$u){$u=User::create(['name'=>$identity['name'],'email'=>$identity['email'],'password'=>bin2hex(random_bytes(48))]);$u->forceFill(['voice_workspace_id'=>1,'voice_role'=>'admin'])->save();}abort_unless($u->voice_enabled&&(int)$u->voice_workspace_id===1,403);return $u;});
   return $this->reply(['tenant'=>$cfg['slug'],'grant'=>$calling->reserve($u->id,$d['operator'],$d)]);
  }
  $call=$calling->call($d['operator'],$d['request_id']);
  if($call&&$d['action']==='cancel')app(VoiceCalling::class)->cancel(1,$call->user_id,$call->id);
  if($call&&$d['action']==='reconcile'){
   abort_unless($call->method==='programmable_voice',422,'O resultado SIP é confirmado pelo Asterisk. Aguarde o retorno da telefonia.');
   app(TwilioVoiceCalling::class)->reconcile(1,$call->user_id,$call->id);
  }
  return $this->reply(['tenant'=>$cfg['slug'],'call'=>$calling->state($d['operator'],$d['request_id'])]);
 }
}
