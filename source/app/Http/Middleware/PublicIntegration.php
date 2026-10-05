<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,RateLimiter};
class PublicIntegration {
 public function handle(Request $r,Closure $next,string $scope){$token=$r->bearerToken();abort_unless(is_string($token)&&preg_match('/^mai_[a-f0-9]{64}$/D',$token),401);$i=DB::table('ma_integrations')->where('token_hash',hash('sha256',$token))->where('active',true)->first();abort_unless($i,401);abort_unless(in_array($scope,json_decode($i->scopes,true),true),403,'Escopo não autorizado.');abort_unless($i->workspace_id===1,403);$key='integration:'.$i->id;abort_if(RateLimiter::tooManyAttempts($key,120),429,'Limite de 120 requisições por minuto.');RateLimiter::hit($key,60);abort_if(strlen($r->getContent())>262144,413);$r->attributes->set('integration',$i);DB::table('ma_integrations')->where('id',$i->id)->update(['last_used_at'=>now()]);$response=$next($r);$response->headers->set('Cache-Control','no-store, private');return $response;}
}
