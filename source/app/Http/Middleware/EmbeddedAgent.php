<?php
namespace App\Http\Middleware;
use Closure;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Auth};
class EmbeddedAgent {
 public function handle(Request $r,Closure $next){$token=$r->bearerToken();abort_unless(is_string($token)&&preg_match('/^mae_[a-f0-9]{64}$/D',$token),401,'Sessão incorporada ausente.');$s=DB::table('ma_embed_sessions')->where('token_hash',hash('sha256',$token))->where('expires_at','>',now())->first();abort_unless($s,401,'Sessão incorporada expirada.');$i=DB::table('ma_integrations')->where('id',$s->integration_id)->where('active',true)->first();abort_unless($i&&in_array('voice:embed',json_decode($i->scopes,true),true)&&in_array($s->user_id,json_decode($i->agent_ids,true),true),401);$u=User::where('voice_workspace_id',$i->workspace_id)->where('voice_enabled',true)->where('voice_role','agent')->find($s->user_id);abort_unless($u,403);abort_unless((int)$s->auth_version===(int)$u->voice_auth_version,401,'Acesso do atendente alterado. Emita uma nova sessão.');abort_if($r->input('method')==='sip_trunk',422,'O discador incorporável utiliza Twilio API.');Auth::onceUsingId($u->id);$r->setUserResolver(fn()=>$u);$response=$next($r);$response->headers->set('Cache-Control','no-store, private');return $response;}
}
