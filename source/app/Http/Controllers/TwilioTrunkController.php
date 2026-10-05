<?php

namespace App\Http\Controllers;

use App\Services\TwilioTrunk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TwilioTrunkController extends Controller
{
    public function show(Request $request, TwilioTrunk $trunk)
    {
        // This is the MA's original installation, not a shared provider for future tenants.
        abort_unless((int) $request->user()->voice_workspace_id === 1 && DB::table('voice_workspaces')->where('id', 1)->exists(), 403);
        return response()->json($trunk->status() + ['methods' => ['sip_trunk' => app(\App\Services\VoiceCallingConfig::class)->status(), 'programmable_voice' => app(\App\Services\VoiceCallingConfig::class)->status('programmable_voice')]])->header('Cache-Control', 'no-store, private');
    }
}
