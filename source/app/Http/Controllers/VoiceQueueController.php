<?php

namespace App\Http\Controllers;

use App\Services\VoiceLab;
use App\Services\VoiceQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VoiceQueueController extends Controller
{
    private function workspace(Request $r): int
    {
        $w = (int) $r->user()->voice_workspace_id;
        abort_unless($w && DB::table('voice_workspaces')->where('id', $w)->exists(), 403);
        return $w;
    }

    public function index(Request $r, VoiceQueue $queues)
    {
        return response()->json($queues->snapshot($this->workspace($r), $r->user()->id))->header('Cache-Control', 'no-store, private');
    }

    public function configure(Request $r, VoiceQueue $queues, ?int $id = null)
    {
        $d = $r->validate(['name' => 'required|string|max:160', 'campaign_id' => 'required|integer', 'strategy' => 'required|in:fifo,priority', 'wrapup_seconds' => 'required|integer|between:0,120', 'agent_ids' => 'required|array|min:1|max:100', 'agent_ids.*' => 'required|integer|distinct', 'revision' => 'sometimes|integer|min:0', 'mode' => 'sometimes|in:simulation']);
        return $queues->configure($this->workspace($r), $r->user()->id, $d, $id);
    }

    public function transition(Request $r, VoiceQueue $queues, int $id)
    {
        $d = $r->validate(['status' => 'required|in:running,paused']);
        return $queues->transition($this->workspace($r), $r->user()->id, $id, $d['status']);
    }

    public function populate(Request $r, VoiceQueue $queues, int $id)
    {
        return $queues->populate($this->workspace($r), $r->user()->id, $id);
    }

    public function priority(Request $r, VoiceQueue $queues, int $id)
    {
        $d = $r->validate(['priority' => 'required|integer|between:0,100']);
        $queues->priority($this->workspace($r), $r->user()->id, $id, $d['priority']);
        return response('', 204);
    }

    public function presence(Request $r, VoiceQueue $queues)
    {
        $d = $r->validate(['status' => 'required|in:available,paused,offline', 'pause_reason' => 'nullable|required_if:status,paused|string|max:160','session_id'=>'nullable|uuid','all_queues'=>'sometimes|boolean','queue_ids'=>'required_if:all_queues,false|nullable|array|max:100','queue_ids.*'=>'integer|min:1|distinct']);
        $queues->presence($this->workspace($r), $r->user()->id, $d['status'], $d['pause_reason'] ?? null, ($d['all_queues']??!array_key_exists('queue_ids',$d))?null:($d['queue_ids']??[]),$d['session_id']??null,$d['status']==='available'||array_key_exists('queue_ids',$d)||isset($d['all_queues']));
        return response('', 204);
    }

    public function heartbeat(Request $r, VoiceQueue $queues)
    {
        $d=$r->validate(['session_id'=>'nullable|uuid']);$queues->presence($this->workspace($r), $r->user()->id,null,null,null,$d['session_id']??null);
        return response('', 204);
    }

    public function claim(Request $r, VoiceQueue $queues, int $id)
    {
        $d = $r->validate(['idempotency_key' => 'required|uuid']);
        return $queues->claim($this->workspace($r), $r->user()->id, $id, $d['idempotency_key']);
    }

    public function finish(Request $r, VoiceQueue $queues, string $id)
    {
        $d = $r->validate(['outcome' => ['required', Rule::in(VoiceLab::OUTCOMES)], 'qualification' => ['nullable', 'required_if:outcome,answered', 'prohibited_unless:outcome,answered', Rule::in(VoiceLab::QUALIFICATIONS)], 'callback_at' => 'nullable|required_if:qualification,callback|date|after:now', 'notes' => 'nullable|string|max:2000']);
        return $queues->finish($this->workspace($r), $r->user()->id, $id, $d);
    }
}
