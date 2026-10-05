<?php

namespace App\Http\Controllers;

use App\Services\VoiceCalling;
use App\Services\VoiceCallingConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoiceCallingController extends Controller
{
    private function workspace(Request $r): int
    {
        abort_unless((int) $r->user()->voice_workspace_id === 1, 403);

        return 1;
    }

    public function index(Request $r)
    {
        $w = $this->workspace($r);
        app(VoiceCalling::class)->expire();

        $manage = in_array($r->user()->voice_role, ['admin', 'supervisor'], true);
        $reserved = DB::table('voice_live_reservations')->where('workspace_id',$w)->where('user_id',$r->user()->id)->whereIn('status', \App\Services\VoiceLiveQueue::ACTIVE)->pluck('contact_id');
        $campaignIds = collect(app(\App\Services\VoiceLiveQueue::class)->snapshot($w,$r->user()->id)['queues'])->flatMap(fn($q)=>$q->campaign_ids)->unique();
        $status = function (string $method = 'sip_trunk') use ($manage) {
            $s = app(VoiceCallingConfig::class)->status($method);
            if (! $manage) $s['allowed_recipients'] = [];
            return $s;
        };
        return response()->json(['connection' => $status(), 'methods' => ['sip_trunk' => $status(), 'programmable_voice' => $status('programmable_voice')], 'calls' => DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('user_id', $r->user()->id)->orderByDesc('created_at')->limit(50)->get(['id', 'method', 'contact_id', 'campaign_id', 'destination', 'caller_id', 'status', 'dial_status', 'cause', 'bill_seconds', 'started_at', 'answered_at', 'ended_at', 'capacity_released_at', 'created_at']), 'contacts' => DB::table('voice_contacts')->where('workspace_id', $w)->when(! $manage, fn ($q) => $q->whereIn('id', $reserved))->limit(500)->get(['id', 'name', 'phone', 'consent', 'suppressed_at', 'replied_at']), 'campaigns' => DB::table('voice_campaigns')->where('workspace_id', $w)->when(! $manage, fn ($q) => $q->whereIn('id', $campaignIds))->get(['id', 'name'])])->header('Cache-Control', 'no-store, private');
    }

    public function reserve(Request $r)
    {
        $w = $this->workspace($r);
        $d = $r->validate(['method' => 'sometimes|in:sip_trunk,programmable_voice', 'contact_id' => 'required|integer', 'queue_reservation_id'=>'nullable|uuid', 'campaign_id' => 'nullable|integer', 'idempotency_key' => 'required|uuid', 'consent_confirmed' => 'required|accepted', 'consent_evidence' => 'required|string|min:8|max:1000']);

        return response()->json(app(VoiceCalling::class)->reserve($w, $r->user()->id, $d))->header('Cache-Control', 'no-store, private');
    }

    public function cancel(Request $r, string $id)
    {
        return app(VoiceCalling::class)->cancel($this->workspace($r), $r->user()->id, $id);
    }

    public function reconcile(Request $r, string $id)
    {
        return app(\App\Services\TwilioVoiceCalling::class)->reconcile($this->workspace($r), $r->user()->id, $id);
    }

    public function event(Request $r)
    {
        $secret = app(VoiceCallingConfig::class)->read()['event_secret'] ?? '';
        $stamp = $r->header('X-Voice-Timestamp', '');
        abort_unless($secret && ctype_digit($stamp) && abs(time() - (int) $stamp) <= 30 && strlen($r->getContent()) <= 4096 && hash_equals(hash_hmac('sha256', $stamp.'.'.$r->getContent(), $secret), $r->header('X-Voice-Signature', '')), 401);
        $d = $r->validate(['event' => 'required|in:start,answered,finish', 'token' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'channel_id' => ['required', 'regex:/^[a-zA-Z0-9_.:-]{1,120}$/D'], 'dial_status' => 'nullable|in:ANSWER,BUSY,NOANSWER,CANCEL,CHANUNAVAIL,CONGESTION,INVALIDARGS,DONTCALL,TORTURE', 'cause' => ['nullable', 'regex:/^[0-9]{1,3}$/D'], 'bill_seconds' => 'sometimes|integer|between:0,240']);

        return app(VoiceCalling::class)->event($d);
    }
}
