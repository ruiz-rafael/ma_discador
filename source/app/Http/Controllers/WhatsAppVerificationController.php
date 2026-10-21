<?php
namespace App\Http\Controllers;

use App\Services\WhatsAppVerification;
use Illuminate\Http\Request;

class WhatsAppVerificationController extends Controller
{
    public function handle(Request $r, string $action = 'numbers')
    {
        abort_unless((int) $r->user()->voice_workspace_id === 1 && $r->user()->voice_role === 'admin', 403);
        $service = app(WhatsAppVerification::class); $platform = WhatsAppVerification::platform($r);
        if ($action === 'numbers') {
            $result = ['numbers' => $service->numbers(1), 'platform_admin' => $platform];
            if ($platform) $result['policy'] = $service->policy(1);
        } else {
            try {
                if ($action === 'settings') {
                    abort_unless($platform, 403);
                    $d = $r->validate(['enabled' => 'required|boolean', 'waba_id' => ['nullable', 'required_if:enabled,true', 'regex:/^[0-9]{5,40}$/D']]);
                    $service->configure(1, $d);
                    app(\App\Services\VoiceLab::class)->audit(1, $r->user()->id, 'whatsapp.verification_settings', 1);
                    $result = ['policy' => $service->policy(1)];
                } else {
                    $rules = ['number' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/D']];
                    if ($action === 'request') $rules += ['name' => 'required|string|min:2|max:100', 'confirmed' => 'required|accepted'];
                    if ($action === 'verify') $rules += ['code' => ['required', 'regex:/^[0-9]{6}$/D']];
                    $d = $r->validate($rules);
                    $result = match ($action) {
                        'status' => $service->status(1, $d['number']),
                        'inspect' => $service->inspect(1, $d['number']),
                        'request' => $service->request(1, $d['number'], $r->user()->id, trim($d['name'])),
                        'verify' => $service->verify(1, $d['number'], $r->user()->id, $d['code']),
                        default => abort(404),
                    };
                    if (in_array($action, ['request', 'verify'])) app(\App\Services\VoiceLab::class)->audit(1, $r->user()->id, 'whatsapp.verification_'.$action, $result['sender_id'] ?? 0, ['number' => $d['number'], 'state' => $result['state']]);
                }
            } catch (\RuntimeException $e) {
                if (!str_starts_with($e->getMessage(), 'twilio_')) throw $e;
                abort(502, 'Não foi possível consultar o provedor. Aguarde e consulte o andamento; nenhuma solicitação será repetida automaticamente.');
            }
        }
        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }
}
