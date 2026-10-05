<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VoiceCallingConfig
{
    public function directory(): string
    {
        return storage_path('app/private/voice/calling');
    }

    public function read(): ?array
    {
        if (app()->environment('testing')) {
            return config('voice_calling_test');
        }
        $p = $this->directory().'/policy.enc';

        return is_readable($p) ? json_decode(Crypt::decryptString(file_get_contents($p)), true, flags: JSON_THROW_ON_ERROR) : null;
    }

    public function trunk(): ?array
    {
        return app()->environment('testing') ? config('voice_calling_trunk_test') : app(TwilioTrunk::class)->read();
    }

    public function fingerprint(string $method = 'sip_trunk'): string
    {
        return hash('sha256', json_encode([$this->read(), $this->connection($method)], JSON_THROW_ON_ERROR));
    }

    public function connection(string $method): ?array
    {
        return $method === 'programmable_voice' ? app(TwilioVoiceConnection::class)->read() : $this->trunk();
    }

    public function installed(): bool
    {
        if (app()->environment('testing')) {
            return (bool) config('voice_calling_installed_test', false);
        }
        $p = $this->directory().'/installed.json';
        $d = is_readable($p) ? json_decode(file_get_contents($p), true) : [];

        return isset($d['fingerprint']) && hash_equals($this->fingerprint(), $d['fingerprint']);
    }

    public function privateWrite(string $name, string $text): void
    {
        $d = $this->directory();
        if (! is_dir($d)) {
            mkdir($d, 0700, true);
        }chmod($d, 0700);
        $tmp = tempnam($d, '.calling-');
        chmod($tmp, 0600);
        try {
            if (file_put_contents($tmp, $text, LOCK_EX) === false || ! rename($tmp, $d.'/'.$name)) {
                throw new \RuntimeException('Falha na gravação privada.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function save(array $d): void
    {
        $d = Validator::make($d, ['allowed_recipients' => 'required|array|min:1|max:20', 'allowed_recipients.*' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/D'], 'daily_limit' => 'required|integer|between:1,50', 'max_seconds' => 'required|integer|between:30,180', 'ring_seconds' => 'required|integer|between:10,45', 'caller_id_confirmed' => 'required|accepted'])->validate();
        DB::transaction(function () use ($d) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('voice_outbound_calls')->whereNull('capacity_released_at')->exists(), 409, 'Há chamada ou reserva pendente; finalize antes de alterar a política.');
            $old = $this->read();
            $d += ['sip_username' => 'ma-calling', 'sip_password' => $old['sip_password'] ?? bin2hex(random_bytes(32)), 'event_secret' => $old['event_secret'] ?? bin2hex(random_bytes(32))];
            $this->privateWrite('policy.enc', Crypt::encryptString(json_encode($d, JSON_THROW_ON_ERROR)));
            $this->disable();
        });
    }

    public function disable(): void
    {
        $p = $this->directory().'/installed.json';
        if (is_file($p)) {
            unlink($p);
        }
    }

    public function status(string $method = 'sip_trunk'): array
    {
        try {
            $p = $this->read();
            $t = $this->connection($method);
            $api = $method === 'programmable_voice';
            $installed = ! $api && $p && $t && $this->installed();
            $ready = $api ? ($p && $t && ($t['enabled'] ?? false)) : ($installed && app(VoiceAudio::class)->ready());

            return ['method' => $method, 'provider_configured' => (bool) $t, 'enabled' => $api ? (bool) ($t['enabled'] ?? false) : (bool) $installed, 'dial_url' => $api ? TwilioVoiceConnection::BASE.'/dial' : null, 'configured' => (bool) $p, 'trunk_configured' => ! $api && (bool) $t, 'installed' => (bool) $installed, 'ready' => (bool) $ready, 'caller_id' => $t['caller_id'] ?? null, 'allowed_recipients' => $p['allowed_recipients'] ?? [], 'max_seconds' => $p['max_seconds'] ?? 120, 'ring_seconds' => $p['ring_seconds'] ?? 30, 'daily_limit' => $p['daily_limit'] ?? 20, 'concurrency' => 1, 'cps' => 1, 'incoming_enabled' => false];
        } catch (\Throwable) {
            return ['method' => $method, 'provider_configured' => false, 'enabled' => false, 'dial_url' => $method === 'programmable_voice' ? TwilioVoiceConnection::BASE.'/dial' : null, 'configured' => false, 'trunk_configured' => false, 'installed' => false, 'ready' => false, 'configuration_error' => true, 'caller_id' => null, 'allowed_recipients' => [], 'max_seconds' => 120, 'ring_seconds' => 30, 'daily_limit' => 20, 'concurrency' => 1, 'cps' => 1, 'incoming_enabled' => false];
        }
    }
}
