<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\VoiceAudio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoiceAudioController extends Controller
{
    public function __construct(private readonly VoiceAudio $audio) {}

    private function workspace(Request $r): int
    {
        $id = $r->user()->voice_workspace_id;
        abort_unless($id && DB::table('voice_workspaces')->where('id', $id)->exists(), 403);
        return (int) $id;
    }

    public function index(Request $r): array
    {
        $w = $this->workspace($r);
        $this->audio->expire();
        $rows = DB::table('voice_audio_sessions')->where('workspace_id', $w)->where('user_id', $r->user()->id)->whereNull('archived_at')->orderByDesc('created_at')->limit(30)->get(['id', 'status', 'started_at', 'answered_at', 'ended_at', 'duration', 'cause', 'created_at', 'client_metrics']);
        foreach ($rows as $row) $row->client_metrics = $row->client_metrics ? json_decode($row->client_metrics, true) : null;
        return ['ready' => $this->audio->ready(), 'kind' => 'internal_echo', 'max_seconds' => 60, 'max_concurrency' => 2, 'pstn_enabled' => false, 'sessions' => $rows];
    }

    public function authorize(Request $r)
    {
        $this->workspace($r);
        abort_unless($this->audio->configuration()['enabled'] ?? false, 503);
        return response('', 204)->header('Cache-Control', 'no-store');
    }

    public function create(Request $r)
    {
        $w = $this->workspace($r);
        $d = $r->validate(['idempotency_key' => 'required|uuid']);
        return response()->json($this->audio->create($w, $r->user()->id, $d['idempotency_key']))->header('Cache-Control', 'no-store, private');
    }

    public function abandon(Request $r, string $id): array
    {
        $w = $this->workspace($r);
        return DB::transaction(function () use ($r, $w, $id) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $q = DB::table('voice_audio_sessions')->where('workspace_id', $w)->where('user_id', $r->user()->id)->where('id', $id);
            $s = $q->firstOrFail();
            // Browser may cancel an unused grant, but cannot assert a PBX outcome.
            if ($s->status === 'pending') $q->update(['status' => 'cancelled', 'updated_at' => now()]);
            return ['cancelled' => $s->status === 'pending'];
        });
    }

    public function metrics(Request $r, string $id): array
    {
        $w = $this->workspace($r);
        $d = $r->validate(['packets_sent' => 'required|integer|between:0,1000000', 'packets_received' => 'required|integer|between:0,1000000', 'audio_energy' => 'required|numeric|between:0,1000000', 'quality' => 'sometimes|array:source,observed_seconds,sample_count,packets_sent,packets_received,audio_energy,codec,clock_rate,channels,transport,rtt_source,encrypted,loss_percent,jitter_ms,rtt_ms,jitter_p95_ms,rtt_p95_ms,concealment_percent,jitter_buffer_ms,receive_kbps,send_kbps,timeline', 'quality.source' => 'required_with:quality|in:browser_webrtc', 'quality.observed_seconds' => 'required_with:quality|numeric|between:0,90', 'quality.sample_count' => 'required_with:quality|integer|between:0,90', 'quality.packets_sent'=>'required_with:quality|integer|between:0,1000000','quality.packets_received'=>'required_with:quality|integer|between:0,1000000','quality.audio_energy'=>'required_with:quality|numeric|between:0,1000000', 'quality.codec'=>'nullable|string|max:64|regex:~^audio/[a-zA-Z0-9._-]+$~D','quality.clock_rate'=>'nullable|integer|between:8000,192000','quality.channels'=>'nullable|integer|between:1,2','quality.transport'=>'nullable|in:udp,tcp','quality.rtt_source'=>'nullable|in:rtcp,ice','quality.encrypted'=>'nullable|boolean','quality.loss_percent'=>'nullable|numeric|between:0,100','quality.concealment_percent'=>'nullable|numeric|between:0,100','quality.jitter_ms'=>'nullable|numeric|between:0,10000','quality.rtt_ms'=>'nullable|numeric|between:0,10000','quality.jitter_p95_ms'=>'nullable|numeric|between:0,10000','quality.rtt_p95_ms'=>'nullable|numeric|between:0,10000','quality.jitter_buffer_ms'=>'nullable|numeric|between:0,10000','quality.receive_kbps'=>'nullable|numeric|between:0,10000','quality.send_kbps'=>'nullable|numeric|between:0,10000','quality.timeline'=>'required_with:quality|array|max:90','quality.timeline.*'=>'array:seconds,jitter_ms,rtt_ms,loss_percent,receive_kbps,send_kbps','quality.timeline.*.seconds'=>'required|numeric|between:0,90','quality.timeline.*.jitter_ms'=>'nullable|numeric|between:0,10000','quality.timeline.*.rtt_ms'=>'nullable|numeric|between:0,10000','quality.timeline.*.loss_percent'=>'nullable|numeric|between:0,100','quality.timeline.*.receive_kbps'=>'nullable|numeric|between:0,10000','quality.timeline.*.send_kbps'=>'nullable|numeric|between:0,10000']);
        $q = DB::table('voice_audio_sessions')->where('workspace_id', $w)->where('user_id', $r->user()->id)->where('id', $id);
        $q->firstOrFail();
        $q->update(['client_metrics' => json_encode($d), 'updated_at' => now()]);
        return ['recorded' => true, 'source' => 'browser'];
    }

    public function event(Request $r): array
    {
        $secret = $this->audio->configuration()['event_secret'] ?? '';
        $stamp = $r->header('X-Voice-Timestamp', '');
        abort_unless($secret && ctype_digit($stamp) && abs(time() - (int) $stamp) <= 30 && hash_equals(hash_hmac('sha256', $stamp.'.'.$r->getContent(), $secret), $r->header('X-Voice-Signature', '')), 401);
        $d = $r->validate(['event' => 'required|in:start,answered,finish', 'token' => ['required', 'regex:/^[a-f0-9]{64}$/'], 'channel_id' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9_.:-]+$/'], 'duration' => 'sometimes|integer|between:0,90', 'cause' => 'nullable|string|max:30']);
        return $this->audio->event($d);
    }
}
