<?php

namespace App\Http\Controllers;

use App\Services\TwilioWhatsAppConnection;
use App\Services\WhatsAppInbound;
use App\Services\WhatsAppMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Twilio\Security\RequestValidator;

class WhatsAppWebhookController extends Controller
{
    private function verify(Request $r, string $suffix): array
    {
        $c = app(TwilioWhatsAppConnection::class)->require();
        abort_unless($r->getQueryString() === null && str_starts_with($r->header('Content-Type', ''), 'application/x-www-form-urlencoded') && strlen($r->getContent()) <= 32768, 400);
        $parameters = $r->request->all();
        foreach ($parameters as $value) {
            abort_unless(is_string($value), 400);
        }
        abort_unless((new RequestValidator($c['auth_token']))->validate($r->header('X-Twilio-Signature', ''), TwilioWhatsAppConnection::base().$suffix, $parameters), 403);
        abort_unless(($parameters['AccountSid'] ?? null) === $c['account_sid'], 403);

        return $c;
    }

    public function status(Request $r, string $id)
    {
        $c = $this->verify($r, '/status/'.$id);
        $d = $r->validate(['MessageSid' => ['required', 'regex:/^(?:SM|MM)[0-9a-fA-F]{32}$/D'], 'MessageStatus' => 'required|string|max:32', 'ErrorCode' => 'nullable|string|max:20']);
        $m = DB::table('wa_messages')->where('id', $id)->where('account_sid', $c['account_sid'])->where('direction', 'outbound')->firstOrFail();
        app(WhatsAppMessages::class)->apply($m->id, $d['MessageSid'], $d['MessageStatus'], $d['ErrorCode'] ?? null);

        return response('', 204);
    }

    public function inbound(Request $r)
    {
        $c = $this->verify($r, '/inbound');
        $d = $r->validate(['MessageSid' => ['required', 'regex:/^SM[0-9a-fA-F]{32}$/D'], 'From' => ['required', 'regex:/^whatsapp:\+[1-9][0-9]{7,14}$/D'], 'To' => ['required', 'regex:/^whatsapp:\+[1-9][0-9]{7,14}$/D'], 'Body' => 'nullable|string|max:4096', 'OptOutType' => 'nullable|string|max:20', 'ButtonPayload' => 'nullable|string|max:200', 'ButtonText' => 'nullable|string|max:100', 'OriginalRepliedMessageSid' => ['nullable', 'regex:/^(?:SM|MM)[0-9a-fA-F]{32}$/D'], 'ReferralSourceId'=>'nullable|string|max:200','ReferralSourceUrl'=>'nullable|string|max:2000','ReferralHeadline'=>'nullable|string|max:500','ReferralCtwaClid'=>'nullable|string|max:500']);
        app(WhatsAppInbound::class)->record($d, $c);

        return response('<Response/>',200)->header('Content-Type','application/xml');
    }
}
