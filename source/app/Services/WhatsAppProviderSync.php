<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WhatsAppProviderSync
{
    public function run(): array
    {
        if (config('discai.portal')) {
            return ['imported' => 0, 'reconciled' => 0, 'seen' => 0];
        }
        $connection = app(TwilioWhatsAppConnection::class)->read();
        if (! $connection) {
            return ['imported' => 0, 'reconciled' => 0, 'seen' => 0];
        }

        $imported = 0;
        $reconciled = 0;
        $seen = 0;
        $senders = DB::table('wa_senders')->where('provider', 'twilio')->where('account_sid', $connection['account_sid'])->get(['id', 'number']);
        foreach ($senders as $sender) {
            $response = app(TwilioWhatsAppApi::class)->request('GET', 'https://api.twilio.com/2010-04-01/Accounts/'.$connection['account_sid'].'/Messages.json', [
                'To' => 'whatsapp:'.$sender->number,
                'PageSize' => 1000,
            ]);
            foreach ($response['messages'] ?? [] as $message) {
                $seen++;
                $sid = (string) ($message['sid'] ?? '');
                if (($message['account_sid'] ?? null) !== $connection['account_sid']
                    || ! preg_match('/^SM[0-9a-fA-F]{32}$/D', $sid)
                    || ! str_starts_with((string) ($message['direction'] ?? ''), 'inbound')
                    || ($message['to'] ?? null) !== 'whatsapp:'.$sender->number
                    || ! preg_match('/^whatsapp:\+[1-9][0-9]{7,14}$/D', (string) ($message['from'] ?? ''))) {
                    continue;
                }
                if (DB::table('wa_messages')->where('provider_sid', $sid)->exists()) {
                    continue;
                }
                $date = $message['date_sent'] ?? $message['date_created'] ?? null;
                $receivedAt = $date ? CarbonImmutable::parse($date)->utc() : now();
                if ($receivedAt->lt(now()->subDays(2))) {
                    continue;
                }
                app(WhatsAppInbound::class)->record([
                    'MessageSid' => $sid,
                    'From' => $message['from'],
                    'To' => $message['to'],
                    'Body' => (string) ($message['body'] ?? ''),
                ], $connection, $receivedAt, false);
                $imported++;
            }

            $outbound = app(TwilioWhatsAppApi::class)->request('GET', 'https://api.twilio.com/2010-04-01/Accounts/'.$connection['account_sid'].'/Messages.json', [
                'From' => 'whatsapp:'.$sender->number,
                'PageSize' => 1000,
            ]);
            foreach ($outbound['messages'] ?? [] as $message) {
                $seen++;
                $sid = (string) ($message['sid'] ?? '');
                $to = str_replace('whatsapp:', '', (string) ($message['to'] ?? ''));
                if (($message['account_sid'] ?? null) !== $connection['account_sid']
                    || ! preg_match('/^(?:SM|MM)[0-9a-fA-F]{32}$/D', $sid)
                    || ! str_starts_with((string) ($message['direction'] ?? ''), 'outbound')
                    || ($message['from'] ?? null) !== 'whatsapp:'.$sender->number
                    || ! preg_match('/^\+[1-9][0-9]{7,14}$/D', $to)) {
                    continue;
                }
                $date = $message['date_created'] ?? $message['date_sent'] ?? null;
                $createdAt = $date ? CarbonImmutable::parse($date)->utc() : null;
                if (! $createdAt || $createdAt->lt(now()->subDays(2))) {
                    continue;
                }
                $known = DB::table('wa_messages')->where('provider_sid', $sid)->first();
                if ($known) {
                    app(WhatsAppMessages::class)->apply($known->id, $sid, (string) ($message['status'] ?? 'unknown'), isset($message['error_code']) ? (string) $message['error_code'] : null);
                    continue;
                }
                $candidates = DB::table('wa_messages')->where('account_sid', $connection['account_sid'])->where('sender_id', $sender->id)->where('direction', 'outbound')->where('from_number', $sender->number)->where('to_number', $to)->whereNull('provider_sid')->whereBetween('created_at', [$createdAt->subSeconds(15), $createdAt->addSeconds(15)])->get();
                if ($candidates->count() !== 1) {
                    continue;
                }
                $candidate = $candidates->first();
                $localBody = app(WhatsAppMessagePresenter::class)->body($candidate);
                $remoteBody = (string) ($message['body'] ?? '');
                if ($localBody === '' || $remoteBody === '' || ! hash_equals($localBody, $remoteBody)) {
                    continue;
                }
                DB::table('wa_messages')->where('id', $candidate->id)->whereNull('body')->update(['body' => $localBody, 'updated_at' => now()]);
                app(WhatsAppMessages::class)->apply($candidate->id, $sid, (string) ($message['status'] ?? 'unknown'), isset($message['error_code']) ? (string) $message['error_code'] : null);
                $reconciled++;
            }
        }

        return compact('imported', 'reconciled', 'seen');
    }
}
