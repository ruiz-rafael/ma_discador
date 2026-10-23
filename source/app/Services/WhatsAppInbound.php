<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WhatsAppInbound
{
    public function record(array $data, array $connection, ?CarbonInterface $receivedAt = null, bool $automate = true): string
    {
        return DB::transaction(function () use ($data, $connection, $receivedAt, $automate): string {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $existing = DB::table('wa_messages')->where('provider_sid', $data['MessageSid'])->first();
            if ($existing) {
                return $existing->id;
            }

            $to = substr($data['To'], 9);
            $from = substr($data['From'], 9);
            $sender = DB::table('wa_senders')->where('number', $to)->where('account_sid', $connection['account_sid'])->firstOrFail();
            $contact = DB::table('voice_contacts')->where('workspace_id', $sender->workspace_id)->where('phone', $from)->first();
            $interactive = null;
            if (! empty($data['ButtonPayload']) || ! empty($data['ButtonText'])) {
                $original = ! empty($data['OriginalRepliedMessageSid'])
                    ? DB::table('wa_messages')->where('workspace_id', $sender->workspace_id)->where('sender_id', $sender->id)->where('direction', 'outbound')->where('to_number', $from)->where('provider_sid', $data['OriginalRepliedMessageSid'])->first()
                    : null;
                $interactive = ['kind' => 'button_reply', 'id' => $data['ButtonPayload'] ?? '', 'label' => $data['ButtonText'] ?? $data['Body'] ?? '', 'context_id' => $data['OriginalRepliedMessageSid'] ?? null, 'matched_message_id' => $original?->id];
            }

            $body = trim((string) ($data['Body'] ?? '')) !== '' ? (string) $data['Body'] : (string) ($data['ButtonText'] ?? '');
            $createdAt = $receivedAt ? CarbonImmutable::instance($receivedAt)->utc() : now();
            $id = (string) Str::uuid();
            DB::table('wa_messages')->insert([
                'id' => $id,
                'workspace_id' => $sender->workspace_id,
                'sender_id' => $sender->id,
                'contact_id' => $contact?->id,
                'direction' => 'inbound',
                'provider' => 'twilio',
                'idempotency_key' => 'inbound:'.$data['MessageSid'],
                'request_hash' => hash('sha256', $data['MessageSid']),
                'account_sid' => $connection['account_sid'],
                'provider_sid' => $data['MessageSid'],
                'from_number' => $from,
                'to_number' => $to,
                'status' => 'received',
                'body' => $body,
                'interactive' => $interactive ? json_encode($interactive) : null,
                'created_at' => $createdAt,
                'updated_at' => now(),
            ]);
            app(JourneyReplyAttribution::class)->record($id);
            app(ConversationInbox::class)->record($id, array_filter([
                'source' => ! empty($data['ReferralSourceId']) ? 'click_to_whatsapp' : null,
                'source_id' => $data['ReferralSourceId'] ?? null,
                'source_url' => $data['ReferralSourceUrl'] ?? null,
                'headline' => $data['ReferralHeadline'] ?? null,
                'ctwa_clid' => $data['ReferralCtwaClid'] ?? null,
            ]), $automate);

            if ($contact) {
                $normalized = mb_strtoupper(trim($body));
                $stop = ($data['OptOutType'] ?? '') === 'STOP'
                    || ($data['ButtonPayload'] ?? '') === 'discai_opt_out'
                    || in_array($normalized, ['STOP', 'SAIR', 'PARAR', 'CANCELAR', 'NÃO RECEBER OFERTAS'], true);
                $contacts = $stop
                    ? DB::table('voice_contacts')->where('workspace_id', $sender->workspace_id)->where('phone', $from)->pluck('id')->all()
                    : [$contact->id];
                DB::table('voice_contacts')->whereIn('id', $contacts)->update([$stop ? 'suppressed_at' : 'replied_at' => $createdAt, 'updated_at' => now()]);
                DB::table('voice_followups')->where('workspace_id', $sender->workspace_id)->whereIn('contact_id', $contacts)->whereIn('status', ['pending', 'blocked'])->update(['status' => 'cancelled', 'reason' => $stop ? 'Opt-out recebido por WhatsApp.' : 'Resposta recebida por WhatsApp.', 'updated_at' => now()]);
                DB::table('voice_actions')->where('workspace_id', $sender->workspace_id)->whereIn('contact_id', $contacts)->where('status', 'pending')->update(['status' => 'cancelled', 'reason' => $stop ? 'Opt-out recebido por WhatsApp.' : 'Resposta recebida por WhatsApp.', 'updated_at' => now()]);
            }

            return $id;
        });
    }
}
