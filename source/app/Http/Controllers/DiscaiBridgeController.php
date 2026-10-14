<?php
namespace App\Http\Controllers;
use App\Services\Portal\TenantBridge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};
class DiscaiBridgeController extends Controller {
 public function start(Request $r,TenantBridge $b){$c=$b->config();abort_unless($c,404);$state=bin2hex(random_bytes(32));$r->session()->put('discai_state',['hash'=>hash('sha256',$state),'until'=>time()+120]);return redirect()->away($c['portal_url'].'/authorize/'.$c['slug'].'?state='.$state)->header('Referrer-Policy','no-referrer');}
 public function complete(Request $r,TenantBridge $b){$d=$r->validate(['code'=>'required|regex:/^[a-f0-9]{64}$/D','state'=>'required|regex:/^[a-f0-9]{64}$/D']);$state=$r->session()->pull('discai_state');abort_unless($state&&$state['until']>=time()&&hash_equals($state['hash'],hash('sha256',$d['state'])),403,'A solicitação de acesso expirou. Entre pelo portal novamente.');$identity=$b->call('redeem',$d);$u=DB::transaction(function()use($identity){$u=User::where('email',$identity['email'])->lockForUpdate()->first();if(!$u)$u=User::create(['name'=>$identity['name'],'email'=>$identity['email'],'password'=>bin2hex(random_bytes(48))]);abort_unless($u->voice_enabled,403,'Seu acesso local está desativado.');$u->forceFill(['voice_workspace_id'=>1,'voice_role'=>$identity['role']])->save();return $u;});Auth::login($u);$r->session()->regenerate();$r->session()->put('voice_auth_version',(int)$u->voice_auth_version);$r->session()->put('discai_identity',['id'=>$identity['id'],'version'=>$identity['version'],'email'=>$identity['email']]);return redirect('/')->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer');}
}
