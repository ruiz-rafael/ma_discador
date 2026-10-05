<?php

namespace App\Http\Controllers;

use App\Services\TwilioWhatsAppConnection;
use App\Services\WhatsAppMessages;
use App\Services\WhatsAppTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppController extends Controller
{
    private function workspace(Request $r): int
    {
        abort_unless((int) $r->user()->voice_workspace_id === 1 && DB::table('voice_workspaces')->where('id', 1)->exists(), 403);

        return 1;
    }

    public function index(Request $r)
    {
        $w = $this->workspace($r);

        return response()->json(['connection' => app(TwilioWhatsAppConnection::class)->status(), 'senders' => DB::table('wa_senders')->where('workspace_id', $w)->orderByDesc('id')->get(), 'qr_templates' => app(\App\Services\WhatsAppQrTemplates::class)->catalog($w), 'templates' => DB::table('wa_templates')->where('workspace_id', $w)->orderByDesc('created_at')->limit(100)->get()->map(function ($t) {
            $t->variables = json_decode($t->variables, true);

            return $t;
        }), 'messages' => DB::table('wa_messages')->where('workspace_id', $w)->orderByDesc('created_at')->limit(100)->get(['id', 'sender_id', 'contact_id', 'campaign_id', 'template_id', 'direction', 'from_number', 'to_number', 'status', 'error_code', 'body', 'provider_sid', 'provider', 'provider_reference', 'created_at', 'qr_template_id', 'interactive']), 'contacts' => DB::table('voice_contacts')->where('workspace_id', $w)->limit(500)->get(['id', 'name', 'phone', 'consent', 'suppressed_at', 'replied_at']), 'campaigns' => DB::table('voice_campaigns')->where('workspace_id', $w)->get(['id', 'name'])])->header('Cache-Control', 'no-store, private');
    }

    public function sender(Request $r)
    {
        $w = $this->workspace($r);
        $d = $r->validate(['label' => 'required|string|max:160', 'number' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/D'], 'ownership' => 'required|in:external,twilio', 'provider' => 'sometimes|in:twilio,qr', 'provider_sid' => ['nullable', 'regex:/^XE[0-9a-fA-F]{32}$/D']]);
        $d['provider'] = $d['provider'] ?? 'twilio';
        abort_if($d['provider'] === 'qr' && !empty($d['provider_sid']), 422, 'QR Code não utiliza Sender SID.');
        abort_if(DB::table('wa_senders')->where('workspace_id', $w)->where('number', $d['number'])->exists() || (! empty($d['provider_sid']) && DB::table('wa_senders')->where('provider_sid', $d['provider_sid'])->exists()), 409, 'Número ou Sender SID já cadastrado.');
        $id = DB::table('wa_senders')->insertGetId($d + ['workspace_id' => $w, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('wa_senders')->find($id);
    }

    public function senderSid(Request $r, int $id)
    {
        $w = $this->workspace($r);
        $d = $r->validate(['provider_sid' => ['required', 'regex:/^XE[0-9a-fA-F]{32}$/D']]);
        $s = app(WhatsAppMessages::class)->sender($w, $id);
        abort_unless($s->provider === 'twilio', 422);
        abort_if($s->provider_sid && $s->provider_sid !== $d['provider_sid'], 409, 'Remetente já vinculado. Cadastre outro número para outra identidade.');
        abort_if(DB::table('wa_senders')->where('provider_sid', $d['provider_sid'])->where('id', '!=', $id)->exists(), 409, 'Sender SID já utilizado.');
        DB::table('wa_senders')->where('id', $id)->update($d + ['updated_at' => now()]);

        return app(WhatsAppMessages::class)->sender($w, $id);
    }

    public function syncSender(Request $r, int $id)
    {
        return app(WhatsAppMessages::class)->syncSender($this->workspace($r), $id);
    }

    public function template(Request $r)
    {
        return app(WhatsAppTemplates::class)->create($this->workspace($r), $r->all());
    }

    public function publish(Request $r, string $id)
    {
        return app(WhatsAppTemplates::class)->publish($this->workspace($r), $id);
    }

    public function approval(Request $r, string $id)
    {
        $d = $r->validate(['submit' => 'required|boolean']);

        return app(WhatsAppTemplates::class)->approval($this->workspace($r), $id, $d['submit']);
    }

    public function reconcileTemplate(Request $r, string $id)
    {
        $d = $r->validate(['content_sid' => ['required', 'regex:/^HX[0-9a-fA-F]{32}$/D']]);

        return app(WhatsAppTemplates::class)->reconcile($this->workspace($r), $id, $d['content_sid']);
    }

    public function send(Request $r)
    {
        $w = $this->workspace($r);
        $d = $r->validate(['sender_id' => 'required|integer', 'template_id' => 'required|uuid', 'contact_id' => 'required|integer', 'campaign_id' => 'nullable|integer', 'idempotency_key' => 'required|uuid', 'variables' => 'present|array|max:10', 'consent_confirmed' => 'required|accepted', 'consent_evidence' => 'required|string|min:8|max:1000']);

        return app(WhatsAppMessages::class)->send($w, $r->user()->id, $d);
    }

    public function reconcileMessage(Request $r, string $id)
    {
        $d = $r->validate(['message_sid' => ['required', 'regex:/^SM[0-9a-fA-F]{32}$/D']]);

        return app(WhatsAppMessages::class)->reconcile($this->workspace($r),$id,$d['message_sid']);
    }
    public function qr(Request $r, int $id)
    {
        $w = $this->workspace($r);
        $action = $r->isMethod('post') ? 'connect' : ($r->isMethod('delete') ? 'disconnect' : 'status');
        return response()->json(app(\App\Services\WhatsAppQr::class)->session($w, $id, $action))->header('Cache-Control', 'no-store, private');
    }

    public function qrSend(Request $r)
    {
        $d = $r->validate(['sender_id' => 'required|integer', 'contact_id' => 'required|integer', 'campaign_id' => 'nullable|integer', 'idempotency_key' => 'required|uuid', 'body' => 'required_without:qr_template_id|nullable|string|max:4000', 'qr_template_id' => 'nullable|uuid', 'experimental_confirmed' => 'sometimes|boolean', 'consent_confirmed' => 'required|accepted', 'consent_evidence' => 'required|string|min:8|max:1000']);
        return app(\App\Services\WhatsAppQr::class)->send($this->workspace($r), $r->user()->id, $d);
    }

    public function qrTemplate(Request $r)
    {
        return app(\App\Services\WhatsAppQrTemplates::class)->create($this->workspace($r), $r->all());
    }

    public function qrPreview(Request $r)
    {
        $d = $r->validate(['qr_template_id' => 'required|uuid', 'contact_id' => 'required|integer', 'campaign_id' => 'nullable|integer']);
        return app(\App\Services\WhatsAppQrTemplates::class)->render($this->workspace($r), $d['qr_template_id'], $d['contact_id'], $d['campaign_id'] ?? null);
    }

    public function retryFollowup(Request $r, string $id)
    {
        app(\App\Services\VoiceFollowups::class)->retry($this->workspace($r), $id);
        return response()->json(['status' => 'pending']);
    }

}
