<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsAppQr
{
    public function config(): array
    {
        $c = app()->environment('testing') ? config('whatsapp_qr_test') : (is_readable($f = storage_path('app/private/voice/whatsapp/qr.json')) ? json_decode(file_get_contents($f), true) : null);
        abort_unless($c && !empty($c['token']), 503, 'Conector QR Code indisponível.');
        return $c;
    }

    public function request(string $method, string $path, array $body = []): array
    {
        $c = $this->config();
        try {
            $r = Http::withToken($c['token'])->acceptJson()->connectTimeout(2)->timeout(15)->withoutRedirecting()->send($method, ($c['url'] ?? 'http://whatsapp-qr:3000').$path, ['json' => $body]);
            abort_unless($r->successful(), 503, 'Conector QR não confirmou a operação. Consulte o estado antes de repetir.');
            return $r->json();
        } catch (\Illuminate\Http\Client\ConnectionException) {
            abort(503, 'Conector QR sem resposta.');
        }
    }

    public function session(int $w, int $id, string $action = 'status'): array
    {
        $s = app(WhatsAppMessages::class)->sender($w, $id);
        abort_unless($s->provider === 'qr', 422, 'Este remetente utiliza a API Twilio.');
        $r = $this->request($action === 'connect' ? 'POST' : ($action === 'disconnect' ? 'DELETE' : 'GET'), '/sessions/'.$s->id, ['number' => $s->number]);
        $online = ($r['status'] ?? '') === 'connected' && ($r['number'] ?? '') === $s->number;
        DB::table('wa_senders')->where('id', $id)->update(['status' => $online ? 'ONLINE' : 'OFFLINE', 'synced_at' => now(), 'updated_at' => now()]);
        return $r + ['verified' => $online];
    }

    public function send(int $w, int $user, array $d, ?string $followup = null): object
    {
        $s = app(WhatsAppMessages::class)->sender($w, $d['sender_id']);
        abort_unless($s->provider === 'qr', 422);
        $templateId = $d['qr_template_id'] ?? null;
        abort_if($templateId && ($d['experimental_confirmed'] ?? false) !== true, 422, 'Confirme o uso experimental dos botões por QR.');
        $hashData = [$user, $d['sender_id'], $d['contact_id'], $d['campaign_id'] ?? null, $templateId ? null : $d['body'], $d['consent_evidence']];
        if ($templateId) $hashData[] = $templateId;
        $hash = hash('sha256', json_encode($hashData));
        $old = DB::table('wa_messages')->where('workspace_id', $w)->where('idempotency_key', $d['idempotency_key'])->first();
        if ($old) { abort_unless($old->request_hash === $hash, 409, 'Identificação já utilizada.'); return $old; }
        $buttons = [];
        if ($templateId) {
            $rendered = app(WhatsAppQrTemplates::class)->render($w, $templateId, $d['contact_id'], $d['campaign_id'] ?? null);
            $d['body'] = $rendered['body'];
            $buttons = $rendered['buttons'];
        }
        $session = $this->session($w, $s->id);
        abort_unless($session['verified'], 422, 'Conecte o número correto pelo QR Code.');
        abort_if($templateId && !($session['capabilities']['experimental_buttons'] ?? false), 422, 'O conector QR ainda não oferece botões experimentais.');
        $m = DB::transaction(function () use ($w, $user, $d, $s, $hash, $followup, $templateId, $buttons) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $old = DB::table('wa_messages')->where('workspace_id', $w)->where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) { abort_unless($old->request_hash === $hash, 409); return ['row' => $old, 'new' => false]; }
            app(OperationPolicy::class)->messages($w);
            $contact = DB::table('voice_contacts')->where('workspace_id', $w)->where('id', $d['contact_id'])->firstOrFail();
            abort_unless($contact->consent && $contact->consent_evidence && !$contact->suppressed_at && !app(CadenceReentry::class)->replyBlocks($contact,$followup?DB::table('voice_followups')->where('id',$followup)->value('run_id'):null), 422, 'Contato sem autorização ou com abordagem interrompida.');
            if ($followup) { app(VoiceFollowups::class)->assertEligible($followup); }
            $c = $this->config();
            abort_unless(in_array($contact->phone, $c['allowed_recipients'] ?? [], true), 422, 'Destino fora da lista de homologação do WhatsApp QR.');
            abort_if(DB::table('wa_messages')->where('provider', 'qr')->where('direction', 'outbound')->where('created_at', '>=', now()->startOfDay())->count() >= ($c['daily_limit'] ?? 10), 429, 'Limite diário do WhatsApp QR atingido.');
            if (!empty($d['campaign_id'])) {
                $campaign = DB::table('voice_campaigns')->where('workspace_id', $w)->where('id', $d['campaign_id'])->firstOrFail();
                $settings = app(WhatsAppNumbers::class)->normalize($w, json_decode($campaign->settings, true));
                abort_unless($settings['whatsapp_enabled'] && (int) ($settings['whatsapp_sender_id'] ?? 0) === $s->id && $settings['whatsapp_number'] === $s->number, 422, 'Remetente diferente da campanha.');
                abort_unless(DB::table('voice_members')->where('campaign_id', $campaign->id)->where('contact_id', $contact->id)->exists(), 422);
            }
            $id = (string) Str::uuid();
            DB::table('wa_messages')->insert(['id' => $id, 'run_id'=>$followup?DB::table('voice_followups')->where('id',$followup)->value('run_id'):null, 'workspace_id' => $w, 'sender_id' => $s->id, 'contact_id' => $contact->id, 'campaign_id' => $d['campaign_id'] ?? null, 'user_id' => $user, 'qr_template_id' => $templateId, 'interactive' => $templateId ? json_encode(['mode' => 'experimental_buttons', 'buttons' => $buttons]) : null, 'provider' => 'qr', 'direction' => 'outbound', 'idempotency_key' => $d['idempotency_key'], 'request_hash' => $hash, 'account_sid' => 'qr:'.$s->id, 'from_number' => $s->number, 'to_number' => $contact->phone, 'status' => 'sending', 'body' => $d['body'], 'consent_evidence' => $d['consent_evidence'], 'created_at' => now(), 'updated_at' => now()]);
            return ['row' => DB::table('wa_messages')->find($id), 'new' => true];
        });
        if (!$m['new']) { return $m['row']; }
        $m = $m['row'];
        app(ConversationInbox::class)->record($m->id);
        try {
            if ($followup) { app(VoiceFollowups::class)->assertEligible($followup); }
            $contact = DB::table('voice_contacts')->find($m->contact_id);
            if (app(OperationPolicy::class)->get($w)['paused'] || !$contact->consent || $contact->suppressed_at || app(CadenceReentry::class)->replyBlocks($contact,$m->run_id) || $contact->phone !== $m->to_number) {
                DB::table('wa_messages')->where('id', $m->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            } else {
                $payload = ['id' => $m->id, 'number' => $s->number, 'to' => $m->to_number, 'text' => $m->body];
                if ($templateId) $payload['interactive'] = json_decode($m->interactive, true);
                $r = $this->request('POST', '/sessions/'.$s->id.'/messages', $payload);
                $this->apply($m->id, $r);
            }
        } catch (\Throwable) {
            DB::table('wa_messages')->where('id', $m->id)->where('status', 'sending')->update(['status' => 'unknown', 'updated_at' => now()]);
        }
        return DB::table('wa_messages')->find($m->id);
    }

    public function apply(string $id, array $r): void
    {
        $rank = ['sending' => 0, 'unknown' => 0, 'sent' => 1, 'failed' => 2, 'delivered' => 3, 'read' => 4];
        DB::transaction(function () use ($id, $r, $rank) {
            $m = DB::table('wa_messages')->where('id', $id)->where('provider', 'qr')->lockForUpdate()->firstOrFail();
            $status = $r['status'] ?? 'unknown';
            if ($m->status !== 'cancelled' && isset($rank[$status]) && $rank[$status] >= ($rank[$m->status] ?? 0)) {
                DB::table('wa_messages')->where('id', $id)->update(['status' => $status, 'provider_reference' => $r['reference'] ?? $m->provider_reference, 'updated_at' => now()]);
                DB::table('wa_events')->insertOrIgnore(['event_hash'=>hash('sha256','qr:'.$id.':'.$status),'message_id'=>$id,'status'=>$status,'created_at'=>now()]);
                app(IntegrationEvents::class)->emit($m->workspace_id,'message.status',$id,'message:'.$id.':'.$status,['status'=>$status]);
            }
        });
    }

    public function poll(): void
    {
        foreach (DB::table('wa_senders')->where('provider', 'qr')->get() as $s) {
            try {
                $this->session($s->workspace_id, $s->id);
                $events = $this->request('GET', '/sessions/'.$s->id.'/events');
                foreach ($events['events'] ?? [] as $event) {
                    DB::transaction(function () use ($s, $event) {
                        DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
                        if (($event['kind'] ?? '') === 'status') {
                            $m = DB::table('wa_messages')->where('sender_id', $s->id)->where('id', $event['message_id'])->first();
                            if ($m) { $this->apply($m->id, $event); }
                            return;
                        }
                        if (($event['kind'] ?? '') !== 'inbound' || !preg_match('/^\+[1-9][0-9]{7,14}$/D', $event['from'] ?? '')) { return; }
                        $key = 'qr:'.$s->id.':'.hash('sha256', $event['id']);
                        if (DB::table('wa_messages')->where('workspace_id', $s->workspace_id)->where('idempotency_key', $key)->exists()) { return; }
                        $contact = DB::table('voice_contacts')->where('workspace_id', $s->workspace_id)->where('phone', $event['from'])->first();
                        $body = mb_substr($event['body'] ?? '', 0, 4096);
                        $reply = is_array($event['reply'] ?? null) ? $event['reply'] : null;
                        $interactive = null;
                        if ($reply && is_string($reply['id'] ?? null)) {
                            $replyId = mb_substr($reply['id'], 0, 64);
                            $context = mb_substr((string) ($reply['context_id'] ?? ''), 0, 128);
                            $original = $context ? DB::table('wa_messages')->where('workspace_id', $s->workspace_id)->where('sender_id', $s->id)->where('direction', 'outbound')->where('to_number', $event['from'])->where('provider_reference', $context)->first() : null;
                            $chosen = $original ? collect(json_decode($original->interactive ?? '{}', true)['buttons'] ?? [])->first(fn ($b) => $b['type'] === 'reply' && $b['id'] === $replyId) : null;
                            $interactive = ['kind' => 'button_reply', 'id' => $replyId, 'label' => mb_substr((string) ($reply['label'] ?? ''), 0, 100), 'context_id' => $context, 'matched_message_id' => $chosen ? $original->id : null];
                            if ($chosen) $body = $chosen['label'];
                        }
                        $inboundId = (string) Str::uuid();
                        DB::table('wa_messages')->insert(['id' => $inboundId, 'workspace_id' => $s->workspace_id, 'sender_id' => $s->id, 'contact_id' => $contact?->id, 'provider' => 'qr', 'direction' => 'inbound', 'idempotency_key' => $key, 'request_hash' => hash('sha256', $key), 'account_sid' => 'qr:'.$s->id, 'provider_reference' => $event['id'], 'from_number' => $event['from'], 'to_number' => $s->number, 'status' => 'received', 'body' => $body, 'interactive' => $interactive ? json_encode($interactive) : null, 'created_at' => now(), 'updated_at' => now()]);
                        app(JourneyReplyAttribution::class)->record($inboundId);
                        app(ConversationInbox::class)->record($inboundId);
                        if ($contact) {
                            $stop = in_array(mb_strtoupper(trim($body)), ['STOP', 'SAIR', 'PARAR', 'CANCELAR']) || ($interactive && in_array(strtoupper($interactive['id']), ['STOP', 'SAIR', 'PARAR', 'CANCELAR']));
                            DB::table('voice_contacts')->where('id', $contact->id)->update([$stop ? 'suppressed_at' : 'replied_at' => now(), 'updated_at' => now()]);
                            foreach (['voice_actions', 'voice_followups'] as $table) {
                                DB::table($table)->where('workspace_id', $s->workspace_id)->where('contact_id', $contact->id)->whereIn('status', ['pending', 'blocked'])->update(['status' => 'cancelled', 'reason' => 'Resposta ou interrupção recebida por WhatsApp.', 'updated_at' => now()]);
                            }
                        }
                    });
                    $this->request('POST', '/sessions/'.$s->id.'/events/ack', ['ids' => [$event['event_id']]]);
                }
            } catch (\Throwable) {
                DB::table('wa_senders')->where('id', $s->id)->update(['status' => 'OFFLINE', 'updated_at' => now()]);
            }
        }
    }
}
