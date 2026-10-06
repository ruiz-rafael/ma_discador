<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppOnboarding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppOnboardingController extends Controller
{
    private function workspace(Request $r): int
    {
        abort_unless((int) $r->user()->voice_workspace_id === 1 && in_array($r->user()->voice_role, ['admin', 'supervisor']), 403);
        return 1;
    }

    public function settings(Request $r)
    {
        $w = $this->workspace($r);
        if ($r->isMethod('put')) {
            abort_unless($r->user()->voice_role === 'admin', 403, 'Somente o administrador configura a parceria.');
            $d = $r->validate(['enabled' => 'required|boolean', 'app_id' => ['required_if:enabled,true', 'nullable', 'regex:/^[0-9]{5,40}$/D'], 'config_id' => ['required_if:enabled,true', 'nullable', 'regex:/^[0-9]{5,40}$/D'], 'solution_id' => ['required_if:enabled,true', 'nullable', 'regex:/^[0-9]{5,40}$/D'], 'graph_version' => ['required_if:enabled,true', 'nullable', 'regex:/^v[0-9]{2}\.0$/D']]);
            DB::table('wa_onboarding_settings')->updateOrInsert(['workspace_id' => $w], ['settings' => json_encode($d), 'created_at' => now(), 'updated_at' => now()]);
        }
        return response()->json(['settings' => app(WhatsAppOnboarding::class)->settings($w), 'can_edit' => $r->user()->voice_role === 'admin'])->header('Cache-Control', 'no-store, private');
    }

    public function status(Request $r, int $id)
    {
        return response()->json(app(WhatsAppOnboarding::class)->status($this->workspace($r), $id))->header('Cache-Control', 'no-store, private');
    }

    public function action(Request $r, int $id, string $action)
    {
        $w = $this->workspace($r); $service = app(WhatsAppOnboarding::class);
        $data = [];
        if ($action === 'register') {
            $data = $r->validate(['mode' => 'required|in:own,embedded', 'display_name' => 'required|string|min:2|max:100', 'verification_method' => 'required_if:mode,own|in:sms,voice', 'session_id' => 'required_if:mode,embedded|uuid', 'waba_id' => ['required_if:mode,embedded', 'regex:/^[0-9]{5,40}$/D'], 'confirmed' => 'required|accepted']);
        }
        if ($action === 'verify') $data = $r->validate(['code' => ['required', 'regex:/^[0-9]{6}$/D']]);
        if ($action === 'webhook') $r->validate(['confirmed' => 'required|accepted']);
        try {
            $result = match ($action) {
                'discover' => $service->discover($w, $id),
                'embedded' => $service->beginEmbedded($w, $id, $r->user()->id),
                'register' => $service->register($w, $id, $r->user()->id, $data),
                'verify' => $service->verify($w, $id, $data['code']),
                'webhook' => $service->webhook($w, $id),
                default => abort(404),
            };
        } catch (\RuntimeException $e) {
            if (!str_starts_with($e->getMessage(), 'twilio_')) throw $e;
            abort(502, 'Não foi possível confirmar a consulta na Twilio. Confira a conexão e tente consultar novamente.');
        }
        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }
}
