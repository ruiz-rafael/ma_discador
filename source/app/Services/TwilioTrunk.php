<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;

/** Preparation only: this service never places a call or enables a PBX route. */
class TwilioTrunk
{
    public function validate(array $data): array
    {
        return Validator::make($data, [
            'account_sid' => ['required', 'regex:/^AC[a-fA-F0-9]{32}$/D'],
            'trunk_sid' => ['required', 'regex:/^TK[a-fA-F0-9]{32}$/D'],
            'termination_host' => ['required', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.pstn\.twilio\.com$/D'],
            'sip_username' => ['required', 'regex:/^[A-Za-z0-9_-]{3,64}$/D'],
            // Restrict configuration metacharacters; never interpolate arbitrary PJSIP lines.
            'sip_password' => ['required', 'string', 'min:12', 'max:128', 'regex:~^[A-Za-z0-9!@$%^&*()_+={}:.?/-]+$~D'],
            'caller_id' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/D'],
            'edge' => ['required', 'in:ashburn,umatilla,dublin,frankfurt,singapore,tokyo,sao-paulo,sydney'],
            'planned_concurrency' => ['required', 'integer', 'between:1,1000'],
            'planned_cps' => ['required', 'integer', 'between:1,100'],
        ])->validate();
    }

    private function directory(): string
    {
        return storage_path('app/private/voice/twilio');
    }

    public function save(array $data): void
    {
        $data = $this->validate($data);
        $dir = $this->directory();
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        chmod($dir, 0700);
        $tmp = tempnam($dir, '.config-');
        try {
            chmod($tmp, 0600);
            if (file_put_contents($tmp, Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), LOCK_EX) === false) {
                throw new \RuntimeException('Falha ao salvar configuração privada.');
            }
            if (! rename($tmp, $dir.'/connection.enc')) {
                throw new \RuntimeException('Falha ao publicar configuração privada.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function read(): ?array
    {
        $file = $this->directory().'/connection.enc';
        if (! is_file($file)) {
            return null;
        }

        return $this->validate(json_decode(Crypt::decryptString(file_get_contents($file)), true, flags: JSON_THROW_ON_ERROR));
    }

    public function status(): array
    {
        try {
            $data = $this->read();
            $state = $data ? 'configured_unverified' : 'awaiting_configuration';
        } catch (\Throwable) {
            $data = null;
            $state = 'configuration_unavailable';
        }

        // Explicit allowlist: no SID, credential, caller ID, or file path in browser responses.
        return ['provider' => 'twilio', 'product' => 'elastic_sip_trunking', 'state' => $state,
            'external_calls_enabled' => app(VoiceCallingConfig::class)->status()['ready'], 'incoming_calls_enabled' => false,
            'planned_concurrency' => $data['planned_concurrency'] ?? null,
            'planned_cps' => $data['planned_cps'] ?? null,
            'capacity_verified' => false, 'number_verified' => false];
    }

    public function render(array $data): string
    {
        $d = $this->validate($data);
        $host = str_replace('.pstn.twilio.com', '.pstn.'.$d['edge'].'.twilio.com', $d['termination_host']);

        return <<<CONF
; STAGED ONLY. Not included by live pjsip.conf. No registration or outbound dialplan.
; Verify CA bundle, media route, account permissions and caller ID before deployment.
[ma-twilio-tls]
type=transport
protocol=tls
bind=0.0.0.0:15061
method=tlsv1_2
verify_server=yes
allow_wildcard_certs=yes
ca_list_file=/etc/ssl/certs/ca-certificates.crt
external_signaling_address=217.216.65.237
external_media_address=217.216.65.237
local_net=10.241.50.0/24

[ma-twilio-auth]
type=auth
auth_type=userpass
username={$d['sip_username']}
password={$d['sip_password']}

[ma-twilio-aor]
type=aor
contact=sip:{$host}:5061\;transport=tls

[ma-twilio-out]
type=endpoint
transport=ma-twilio-tls
context=ma-twilio-denied
outbound_auth=ma-twilio-auth
aors=ma-twilio-aor
from_domain={$d['termination_host']}
from_user={$d['caller_id']}
callerid={$d['caller_id']}
disallow=all
allow=ulaw,alaw
media_encryption=sdes
media_encryption_optimistic=no
direct_media=no
ice_support=no
rtcp_mux=no
rtp_symmetric=no
allow_transfer=no
allow_subscribe=no

CONF;
    }

    public function stage(): void
    {
        $data = $this->read();
        if (! $data) {
            throw new \RuntimeException('Cadastre a conexão primeiro.');
        }
        $path = $this->directory().'/pjsip-staged.conf';
        $tmp = tempnam($this->directory(), '.pjsip-');
        try {
            chmod($tmp, 0600);
            if (file_put_contents($tmp, $this->render($data), LOCK_EX) === false || ! rename($tmp, $path)) {
                throw new \RuntimeException('Falha ao gerar preparação do tronco.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
