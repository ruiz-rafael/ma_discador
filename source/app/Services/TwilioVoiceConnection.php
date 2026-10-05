<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VoiceGrant;

class TwilioVoiceConnection
{
    public const BASE = 'https://ma.zyrex.ia.br/callbacks/twilio/voice';

    public function read(): ?array
    {
        if (app()->environment('testing')) {
            return config('twilio_voice_test');
        }
        $file = app(VoiceCallingConfig::class)->directory().'/programmable.enc';
        return is_readable($file) ? json_decode(Crypt::decryptString(file_get_contents($file)), true, flags: JSON_THROW_ON_ERROR) : null;
    }

    public function validate(array $data): array
    {
        return Validator::make($data, [
            'account_sid' => ['required', 'regex:/^AC[0-9a-fA-F]{32}$/D'],
            'api_key' => ['required', 'regex:/^SK[0-9a-fA-F]{32}$/D'],
            'api_secret' => 'required|string|min:16|max:200',
            'auth_token' => 'required|string|min:16|max:200',
            'application_sid' => ['required', 'regex:/^AP[0-9a-fA-F]{32}$/D'],
            'caller_id' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/D'],
            'edge' => 'required|in:ashburn,umatilla,dublin,frankfurt,singapore,tokyo,sao-paulo,sydney',
            'enabled' => 'required|boolean',
        ])->validate();
    }

    public function save(array $data): void
    {
        $data = $this->validate($data);
        DB::transaction(function () use ($data) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('voice_outbound_calls')->whereNull('capacity_released_at')->exists(), 409, 'Finalize ou reconcilie as chamadas antes de alterar credenciais.');
            app(VoiceCallingConfig::class)->privateWrite('programmable.enc', Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)));
        });
    }

    public function disable(): void
    {
        DB::transaction(function () {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ($data = $this->read()) {
                $data['enabled'] = false;
                app(VoiceCallingConfig::class)->privateWrite('programmable.enc', Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)));
            }
        });
    }

    public static function identity(string $id): string
    {
        return 'ma_'.str_replace('-', '', $id);
    }

    public function grant(object $row, array $policy): array
    {
        $c = $this->read();
        $token = new AccessToken($c['account_sid'], $c['api_key'], $c['api_secret'], 300, self::identity($row->id));
        $voice = new VoiceGrant;
        $voice->setOutgoingApplicationSid($c['application_sid']);
        $voice->setIncomingAllow(false);
        $token->addGrant($voice);
        return ['id' => $row->id, 'method' => 'programmable_voice', 'access_token' => $token->toJWT(), 'edge' => $c['edge'], 'params' => ['Reservation' => $row->id, 'Grant' => hash_hmac('sha256', $row->id, $policy['event_secret'])], 'max_seconds' => $row->max_seconds, 'ring_seconds' => $row->ring_seconds];
    }
}
