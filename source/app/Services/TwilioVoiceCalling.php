<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Twilio\TwiML\VoiceResponse;

class TwilioVoiceCalling
{
    public function hangup(): string
    {
        $xml = new VoiceResponse;
        $xml->hangup();
        return (string) $xml;
    }

    public function dial(array $d, array $connection): string
    {
        return DB::transaction(function () use ($d, $connection) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            app(VoiceCalling::class)->expire();
            $m = DB::table('voice_outbound_calls')->where('id', $d['Reservation'])->first();
            $config = app(VoiceCallingConfig::class);
            // Never render a second Dial, including a retry with the same parent CallSid.
            if (! $m || $m->method !== 'programmable_voice' || $m->status !== 'pending'
                || $m->provider_account !== $connection['account_sid']
                || ! hash_equals($m->grant_hash, hash('sha256', $d['Grant']))
                || $d['From'] !== 'client:'.TwilioVoiceConnection::identity($m->id)
                || ! $config->status('programmable_voice')['ready']
                || ! hash_equals($m->configuration_hash, $config->fingerprint('programmable_voice'))) {
                return $this->hangup();
            }
            try {
                $contact = app(VoiceCalling::class)->contact($m->workspace_id, $m->contact_id);
                app(VoiceEligibility::class)->assertDial($m);
                app(VoiceProviderCatalog::class)->assertOrigin($m);
            } catch (\Throwable) {
                return $this->hangup();
            }
            if ($contact->phone !== $m->destination || ! in_array($m->destination, $config->read()['allowed_recipients'], true)
                || DB::table('voice_outbound_calls')->where('started_at', '>=', now()->subSecond())->exists()) {
                return $this->hangup();
            }
            DB::table('voice_outbound_calls')->where('id', $m->id)->update(['status' => 'dialing', 'channel_id' => $d['CallSid'], 'started_at' => now(), 'updated_at' => now()]);
            app(VoiceLab::class)->audit($m->workspace_id, $m->user_id, 'calling.api.start', $m->id);
            $xml = new VoiceResponse;
            $dial = $xml->dial(null, ['callerId' => $m->caller_id, 'answerOnBridge' => true, 'timeLimit' => $m->max_seconds, 'timeout' => $m->ring_seconds, 'record' => 'do-not-record', 'action' => TwilioVoiceConnection::BASE.'/finish/'.$m->id, 'method' => 'POST']);
            $dial->number($m->destination, ['statusCallback' => TwilioVoiceConnection::BASE.'/status/'.$m->id, 'statusCallbackMethod' => 'POST', 'statusCallbackEvent' => 'initiated ringing answered completed']);
            return (string) $xml;
        });
    }

    public function apply(string $id, string $account, string $parent, ?string $child, string $status, ?int $seconds): void
    {
        DB::transaction(function () use ($id, $account, $parent, $child, $status, $seconds) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $q = DB::table('voice_outbound_calls')->where('id', $id)->where('method', 'programmable_voice')->where('provider_account', $account);
            $m = $q->firstOrFail();
            abort_unless($m->channel_id && hash_equals($m->channel_id, $parent), 403);
            abort_if($child && $m->provider_child_sid && $m->provider_child_sid !== $child, 403);
            if ($m->capacity_released_at) {
                return;
            }
            $update = ['updated_at' => now()];
            if ($child) {
                $update['provider_child_sid'] = $child;
            }
            $terminal = ['completed' => 'completed', 'busy' => 'busy', 'failed' => 'failed', 'no-answer' => 'no_answer', 'canceled' => 'cancelled'];
            if (isset($terminal[$status])) {
                $update += ['status' => $terminal[$status], 'dial_status' => $status, 'bill_seconds' => $seconds ?? 0, 'ended_at' => now(), 'capacity_released_at' => now()];
            } elseif ($status === 'in-progress' && $m->status === 'dialing') {
                $update += ['status' => 'answered', 'answered_at' => now()];
            }
            // Ringing/initiated and delayed answered callbacks never regress a terminal/unknown state.
            $q->update($update);
            app(VoiceFollowups::class)->observe($id);
            app(VoiceLiveQueue::class)->settle();
            app(VoiceLab::class)->audit($m->workspace_id, $m->user_id, 'calling.api.'.$status, $id);
        });
    }

    public function reconcile(int $workspace, int $user, string $id): array
    {
        $m = DB::table('voice_outbound_calls')->where('workspace_id', $workspace)->where('user_id', $user)->where('id', $id)->where('method', 'programmable_voice')->firstOrFail();
        if ($m->capacity_released_at || ! $m->channel_id) {
            return ['reconciled' => false];
        }
        $c = app(TwilioVoiceConnection::class)->read();
        abort_unless($c && $m->provider_account === $c['account_sid'], 409);
        $base = 'https://api.twilio.com/2010-04-01/Accounts/'.$c['account_sid'];
        try {
            $http = Http::withBasicAuth($c['api_key'], $c['api_secret'])->acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting();
            $parent = $http->get($base.'/Calls/'.$m->channel_id.'.json');
            $children = $http->get($base.'/Calls.json', ['ParentCallSid' => $m->channel_id, 'PageSize' => 2]);
            abort_unless($parent->successful() && $children->successful(), 502, 'A Twilio não confirmou a consulta.');
            $p = $parent->json();
            $list = $children->json('calls');
            abort_unless(($p['sid'] ?? null) === $m->channel_id && ($p['account_sid'] ?? null) === $m->provider_account && is_array($list) && count($list) <= 1 && ! $children->json('next_page_uri'), 502);
            if (count($list) === 1) {
                $child = $list[0];
                abort_unless(($child['parent_call_sid'] ?? null) === $m->channel_id && ($child['account_sid'] ?? null) === $m->provider_account && ($child['to'] ?? null) === $m->destination && preg_match('/^CA[0-9a-fA-F]{32}$/D', $child['sid'] ?? ''), 502);
                $status = $child['status'] ?? '';
                abort_unless(in_array($status, ['queued', 'initiated', 'ringing', 'in-progress', 'completed', 'busy', 'failed', 'no-answer', 'canceled']), 502);
                $this->apply($id, $m->provider_account, $m->channel_id, $child['sid'], $status, isset($child['duration']) ? max(0, (int) $child['duration']) : null);
            } else {
                // An empty, potentially delayed list does not prove that no child call exists.
                return ['reconciled' => false];
            }
        } catch (\Illuminate\Http\Client\ConnectionException) {
            abort(502, 'Consulta sem confirmação. A capacidade continua reservada.');
        }
        return ['reconciled' => true];
    }
}
