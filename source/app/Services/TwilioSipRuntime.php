<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** Private PBX state for the tenant's Elastic SIP trunk. No carrier requests. */
class TwilioSipRuntime
{
    public function descriptor(): ?array
    {
        $c = app(VoiceCallingConfig::class);
        $p = $c->read();
        $t = $c->trunk();
        if (!$p || !$t || empty($t['trunk_sid'])) return null;
        $endpoint = 'discai-twilio-'.substr(hash('sha256', $p['event_secret'].'|'.$t['trunk_sid']), 0, 32);
        return ['id'=>$t['trunk_sid'], 'endpoint'=>$endpoint,
            'hash'=>hash('sha256', json_encode([$p, $t], JSON_THROW_ON_ERROR)),
            'caller_id'=>$t['caller_id']];
    }

    public function export(): ?array
    {
        $d = $this->descriptor();
        if (!$d) return null;
        $render = app(TwilioTrunk::class)->render(app(VoiceCallingConfig::class)->trunk());
        [$transport, $routes] = explode('[ma-twilio-auth]', $render, 2);
        $routes = strtr('[ma-twilio-auth]'.$routes, [
            'ma-twilio-auth'=>$d['endpoint'].'-auth', 'ma-twilio-aor'=>$d['endpoint'].'-aor',
            'ma-twilio-out'=>$d['endpoint'], 'ma-twilio-denied'=>'discai-sip-denied',
        ]);
        return $d + ['kind'=>'twilio_tls', 'enabled'=>true, 'configuration'=>$routes, 'transport_configuration'=>$transport];
    }

    public function state(): ?array
    {
        if (app()->environment('testing')) return config('twilio_sip_runtime_test');
        $path = app(VoiceCallingConfig::class)->directory().'/tenant-sip.json';
        return is_readable($path) ? json_decode(file_get_contents($path), true) : null;
    }

    public function ready(): bool
    {
        $d = $this->descriptor(); $s = $this->state();
        return $d && $s && ($s['state']??null)==='installed'
            && hash_equals($d['hash'], $s['hash']??'')
            && isset($s['checked_at']) && CarbonImmutable::parse($s['checked_at'])->between(now()->subSeconds(150), now()->addSeconds(5));
    }

    public function record(array $state): void
    {
        $d = $this->descriptor();
        if (!$d || ($state['id']??null)!==$d['id'] || !hash_equals($d['hash'], $state['hash']??'')) return;
        if (!in_array($state['state']??null, ['installed','error','disabled','no_policy'], true)) return;
        $state = array_intersect_key($state, array_flip(['id','hash','state'])) + ['checked_at'=>now()->toIso8601String()];
        if (app()->environment('testing')) config(['twilio_sip_runtime_test'=>$state]);
        else app(VoiceCallingConfig::class)->privateWrite('tenant-sip.json', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
