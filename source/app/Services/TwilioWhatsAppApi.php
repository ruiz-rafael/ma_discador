<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TwilioWhatsAppApi
{
    public function request(string $method, string $resource, array $data = [], bool $form = false): array
    {
        $c = app(TwilioWhatsAppConnection::class)->require();
        // Only application-constructed paths; redirects and automatic POST retries are disabled.
        $phoneInventory = 'https://api.twilio.com/2010-04-01/Accounts/'.$c['account_sid'].'/IncomingPhoneNumbers.json';
        $phoneLookup = $method === 'GET' && $resource === $phoneInventory;
        abort_unless($phoneLookup || preg_match('~^(https://content\.twilio\.com/v1/Content(?:/HX[0-9a-fA-F]{32}(?:/ApprovalRequests(?:/whatsapp)?)?)?|https://messaging\.twilio\.com/v2/Channels/Senders(?:/XE[0-9a-fA-F]{32})?|https://api\.twilio\.com/2010-04-01/Accounts/AC[0-9a-fA-F]{32}(?:/Messages(?:/(?:SM|MM)[0-9a-fA-F]{32})?)?\.json)$~D', $resource), 500);
        $http = Http::withBasicAuth($c['api_key'], $c['api_secret'])->acceptJson()->connectTimeout(3)->timeout(12)->withoutRedirecting();
        try {
            $r = ($form ? $http->asForm() : $http->asJson())->send($method, $resource, $method === 'GET' ? ['query' => $data] : [$form ? 'form_params' : 'json' => $data]);
        } catch (\Throwable) {
            throw new \RuntimeException('twilio_unknown');
        }
        if (! $r->successful()) {
            $code = $r->json('code');
            throw new TwilioWhatsAppException($r->status(), is_numeric($code) ? (int) $code : null);
        }
        $body = $r->json();
        if (! is_array($body)) {
            throw new \RuntimeException('twilio_unknown');
        }

return $body;
    }

    public function sender(string $sid): array
    {
        return $this->request('GET', 'https://messaging.twilio.com/v2/Channels/Senders/'.$sid);
    }

    public function content(string $sid): array
    {
        return $this->request('GET', 'https://content.twilio.com/v1/Content/'.$sid);
    }

    public function approval(string $sid): array
    {
        return $this->request('GET', 'https://content.twilio.com/v1/Content/'.$sid.'/ApprovalRequests');
    }
}
