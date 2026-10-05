<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** An assigned agent has an operational API, not administrative MA access. */
class VoiceAccess
{
    public function handle(Request $r, Closure $next)
    {
        $user = $r->user();
        if($user && $r->is('api/*') && $r->hasSession()) abort_unless((int)$r->session()->get('voice_auth_version',0)===(int)$user->voice_auth_version,401,'Senha alterada. Entre novamente.');
        if ($user && ! $user->voice_enabled && $r->is('api/*')) {
            $finishing = $r->isMethod('GET') && preg_match('~^api/(?:bootstrap|voice/(?:inbound|calling|operations/(?:catalog|queues|calls/[a-f0-9-]+/history)))$~D', $r->path());
            $finishing = $finishing || ($r->isMethod('POST') && preg_match('~^api/(?:logout|voice/(?:inbound/calls/[a-f0-9-]+/(?:reconcile|disposition)|calling/calls/[a-f0-9-]+/(?:cancel|reconcile)|operations/calls/[a-f0-9-]+/disposition))$~D', $r->path()));
            abort_unless($finishing,403,'Acesso desativado. Conclua o atendimento atual e procure a supervisão.');
        }
        if (! $r->is('api/*') || ! $user?->voice_workspace_id || in_array($user->voice_role, ['admin', 'supervisor'], true)) return $next($r);
        $path = $r->path();
        $read = $r->isMethod('GET') && preg_match('~^api/(?:bootstrap|voice/(?:conversations(?:/[a-f0-9-]+)?|inbound|calling|audio|audio/authorize|operations/(?:catalog|queues|reports|export|calls/[a-f0-9-]+/history)))$~D', $path);
        $write = $r->isMethod('POST') && preg_match('~^api/(?:logout|voice/(?:conversations/[a-f0-9-]+/(?:assign|read|send)|inbound/(?:token|device|calls/[a-f0-9-]+/(?:transfer|reconcile|disposition))|queues/(?:presence|heartbeat)|operations/(?:manual-reservations|queues/\d+/claim|reservations/[a-f0-9-]+/cancel|calls/[a-f0-9-]+/disposition)|calling/calls(?:/[a-f0-9-]+/(?:cancel|reconcile))?|audio/sessions(?:/[a-f0-9-]+/(?:abandon|metrics))?))$~D', $path);
        abort_unless($read || $write, 403, 'Esta área é reservada à supervisão. Use Minha operação para atender.');
        return $next($r);
    }
}
