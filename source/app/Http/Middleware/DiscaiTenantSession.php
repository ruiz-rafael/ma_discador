<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use App\Services\Portal\TenantBridge;
class DiscaiTenantSession {
 public function handle(Request $r,Closure $next){
  if(config('discai.portal')){ $res=$next($r);$res->headers->set('Cache-Control','no-store, private');$res->headers->set('Referrer-Policy','no-referrer');return $res; }
  $bridge=app(TenantBridge::class)->config();
  if(!empty($bridge['required'])&&$r->is('api/voice/team*')&&!$r->isMethod('GET'))abort(409,'Gerencie usuários e convites no portal DiscAI.');
  if(!empty($bridge['required'])&&$r->path()==='login'&&$r->isMethod('POST'))abort(403,'Entre pelo portal DiscAI.');
  if(!empty($bridge['required'])&&$r->is('api/*')&&$r->user()&&!$r->session()->has('discai_identity'))abort(401,'Entre pelo portal DiscAI.');
  if(!empty($bridge['required'])&&$r->path()==='/'&&!$r->user())return response()->view('portal.tenant-entry');
  if($r->is('api/*')&&$r->user()&&$r->hasSession()&&($i=$r->session()->get('discai_identity'))){
   if(!$r->is('api/logout')){try{$d=app(TenantBridge::class)->call('check',['user_id'=>$i['id']]);abort_unless($d['version']===$i['version']&&$d['email']===$r->user()->email,401,'Acesso alterado. Entre novamente pelo portal.');$r->user()->voice_role=$d['role'];}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){if(in_array($e->getStatusCode(),[401,403],true)){\Illuminate\Support\Facades\Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();}throw $e;}}
  }
  return $next($r);
 }
}
