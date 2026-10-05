<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;

class TwilioWhatsAppConnection
{
    public const BASE = 'https://ma.zyrex.ia.br/callbacks/twilio/whatsapp';

    public function read(): ?array
    {
        if (app()->environment('testing')) {
            return config('whatsapp_test_connection');
        }
        $f = storage_path('app/private/voice/whatsapp/connection.enc');

        return is_readable($f) ? json_decode(Crypt::decryptString(file_get_contents($f)), true, flags: JSON_THROW_ON_ERROR) : null;
    }

    public function save(array $d): void
    {
        $d = Validator::make($d, ['account_sid' => ['required', 'regex:/^AC[0-9a-fA-F]{32}$/D'], 'api_key' => ['required', 'regex:/^SK[0-9a-fA-F]{32}$/D'], 'api_secret' => 'required|string|min:16|max:200', 'auth_token' => 'required|string|min:16|max:200', 'allowed_recipients' => 'required|array|min:1|max:20', 'allowed_recipients.*' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/D'], 'daily_limit' => 'required|integer|between:1,100'])->validate();
        $dir = storage_path('app/private/voice/whatsapp');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }chmod($dir, 0700);
        $tmp = tempnam($dir, '.config-');
        chmod($tmp, 0600);
        try {
            if (file_put_contents($tmp, Crypt::encryptString(json_encode($d, JSON_THROW_ON_ERROR)), LOCK_EX) === false || ! rename($tmp, $dir.'/connection.enc')) {
                throw new \RuntimeException('Falha ao salvar configuração.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function require(): array
    {
        try {
            $c = $this->read();
        } catch (\Throwable) {
            abort(503, 'Configuração privada de WhatsApp indisponível.');
        }
        abort_unless($c, 503, 'Cadastre as credenciais da API Twilio antes de conectar o WhatsApp.');

        return $c;
    }

    public function status(): array
    {
        try {
            $c = $this->read();

            return ['configured' => (bool) $c, 'mode' => 'restricted_testing', 'daily_limit' => $c['daily_limit'] ?? null, 'allowed_recipients' => $c['allowed_recipients'] ?? [], 'inbound_url' => self::BASE.'/inbound'];
        } catch (\Throwable) {
            return ['configured' => false, 'mode' => 'configuration_unavailable', 'daily_limit' => null, 'allowed_recipients' => [], 'inbound_url' => self::BASE.'/inbound'];
        }
    }
}
