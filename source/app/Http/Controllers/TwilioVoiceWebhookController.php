<?php

namespace App\Http\Controllers;

use App\Services\TwilioVoiceCalling;
use App\Services\TwilioVoiceConnection;
use Illuminate\Http\Request;
use Twilio\Security\RequestValidator;

class TwilioVoiceWebhookController extends Controller
{
    public function verify(Request $r, string $suffix): array
    {
        $c = app(TwilioVoiceConnection::class)->read();
        abort_unless($c, 503);
        abort_unless($r->getQueryString() === null && str_starts_with($r->header('Content-Type', ''), 'application/x-www-form-urlencoded') && strlen($r->getContent()) <= 32768, 400);
        $parameters = $r->request->all();
        foreach ($parameters as $value) {
            abort_unless(is_string($value), 400);
        }
        abort_unless((new RequestValidator($c['auth_token']))->validate($r->header('X-Twilio-Signature', ''), TwilioVoiceConnection::BASE.$suffix, $parameters), 403);
        abort_unless(($parameters['AccountSid'] ?? null) === $c['account_sid'], 403);
        return $c;
    }

    public function dial(Request $r, TwilioVoiceCalling $calls)
    {
        $c = $this->verify($r, '/dial');
        $d = $r->validate(['Reservation' => 'required|uuid', 'Grant' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'CallSid' => ['required', 'regex:/^CA[0-9a-fA-F]{32}$/D'], 'From' => 'required|string|max:120']);
        return response($calls->dial($d, $c))->header('Content-Type', 'application/xml')->header('Cache-Control', 'no-store');
    }

    public function status(Request $r, string $id, TwilioVoiceCalling $calls)
    {
        $c = $this->verify($r, '/status/'.$id);
        $d = $r->validate(['CallSid' => ['required', 'regex:/^CA[0-9a-fA-F]{32}$/D'], 'ParentCallSid' => ['required', 'regex:/^CA[0-9a-fA-F]{32}$/D'], 'CallStatus' => 'required|in:queued,initiated,ringing,in-progress,completed,busy,failed,no-answer,canceled', 'CallDuration' => 'sometimes|nullable|integer|between:0,86400']);
        $calls->apply($id, $c['account_sid'], $d['ParentCallSid'], $d['CallSid'], $d['CallStatus'], isset($d['CallDuration']) ? (int) $d['CallDuration'] : null);
        return response('', 204);
    }

    public function finish(Request $r, string $id, TwilioVoiceCalling $calls)
    {
        $c = $this->verify($r, '/finish/'.$id);
        $d = $r->validate(['CallSid' => ['required', 'regex:/^CA[0-9a-fA-F]{32}$/D'], 'DialCallSid' => ['sometimes', 'nullable', 'regex:/^CA[0-9a-fA-F]{32}$/D'], 'DialCallStatus' => 'required|in:completed,busy,failed,no-answer,canceled', 'DialCallDuration' => 'sometimes|nullable|integer|between:0,86400']);
        $calls->apply($id, $c['account_sid'], $d['CallSid'], ($d['DialCallSid'] ?? '') ?: null, $d['DialCallStatus'], isset($d['DialCallDuration']) ? (int) $d['DialCallDuration'] : null);
        return response($calls->hangup())->header('Content-Type', 'application/xml');
    }
}
