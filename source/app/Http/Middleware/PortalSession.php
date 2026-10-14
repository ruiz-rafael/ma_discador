<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class PortalSession {
 public function handle(Request $r,Closure $next){
  if(!$r->user())return redirect('/login');
  if(!$r->user()->voice_enabled||(int)$r->session()->get('voice_auth_version',-1)!==(int)$r->user()->voice_auth_version){\Illuminate\Support\Facades\Auth::logout();$r->session()->invalidate();return redirect('/login')->withErrors(['login'=>'Sua sessão expirou. Entre novamente.']);}
  return $next($r);
 }
}
