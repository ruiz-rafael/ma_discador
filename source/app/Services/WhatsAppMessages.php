<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WhatsAppMessages
{
    public function sender(int $w, int $id): object
    {
        return DB::table('wa_senders')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
    }

    public function syncSender(int $w, int $id): object
    {
        $c = app(TwilioWhatsAppConnection::class)->require();
        $s = $this->sender($w, $id);
        abort_unless($s->provider === 'twilio', 422, 'Remetente utiliza QR Code.');
        abort_unless($s->provider_sid, 422, 'Cadastre o Sender SID obtido no console Twilio.');
        $r = app(TwilioWhatsAppApi::class)->sender($s->provider_sid);
        abort_unless(($r['sid'] ?? '') === $s->provider_sid && ($r['sender_id'] ?? '') === 'whatsapp:'.$s->number, 422, 'O remetente da Twilio não corresponde ao número cadastrado.');
        $status = $r['status'] ?? 'UNKNOWN';
        if (! in_array($status, ['ONLINE', 'OFFLINE', 'CREATING', 'PENDING_VERIFICATION', 'VERIFYING', 'ONLINE:UPDATING', 'TWILIO_REVIEW', 'DRAFT', 'STUBBED'])) {
            $status = 'UNKNOWN';
        }
        DB::table('wa_senders')->where('id', $id)->update(['status' => $status, 'account_sid' => $c['account_sid'], 'synced_at' => now(), 'updated_at' => now()]);

        return $this->sender($w, $id);
    }

    public function send(int $w, int $user, array $d, ?string $followup = null): object
    {
        $c = app(TwilioWhatsAppConnection::class)->require();
        $hash = hash('sha256', json_encode([$user, $d['sender_id'], $d['contact_id'], $d['template_id'], $d['campaign_id'] ?? null, (object) $d['variables'], $d['consent_evidence']], JSON_THROW_ON_ERROR));
        $old = DB::table('wa_messages')->where('workspace_id', $w)->where('idempotency_key', $d['idempotency_key'])->first();
        if ($old) {
            abort_unless($old->request_hash === $hash, 409, 'Chave já usada para outra mensagem.');

            return $old;
        }
        // Re-check provider state immediately before reserving; local labels never authorize sending.
        $sender = $this->syncSender($w, $d['sender_id']);
        $template = app(WhatsAppTemplates::class)->approval($w, $d['template_id']);
        abort_unless($sender->status === 'ONLINE' && $template->approval_status === 'approved', 422, 'Remetente precisa estar ONLINE e template aprovado.');
        app(WhatsAppTemplates::class)->variables($template->body, $d['variables']);
        $result = DB::transaction(function () use ($w, $user, $d, $hash, $c, $sender, $template, $followup) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $old = DB::table('wa_messages')->where('workspace_id', $w)->where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) {
                abort_unless($old->request_hash === $hash, 409, 'Chave já usada para outra mensagem.');

                return ['row' => $old, 'created' => false];
            }
            if ($followup) { app(VoiceFollowups::class)->assertEligible($followup); }
            app(OperationPolicy::class)->messages($w);
            $contact = DB::table('voice_contacts')->where('workspace_id', $w)->where('id', $d['contact_id'])->lockForUpdate()->firstOrFail();
            abort_unless($contact->consent && ! $contact->suppressed_at && ! app(CadenceReentry::class)->replyBlocks($contact,$followup?DB::table('voice_followups')->where('id',$followup)->value('run_id'):null), 422, 'Contato sem autorização ou com abordagem interrompida.');
            abort_unless(in_array($contact->phone, $c['allowed_recipients'], true), 422, 'Destino não está na lista privada de homologação.');
            if (! empty($d['campaign_id'])) {
                $campaign = DB::table('voice_campaigns')->where('workspace_id', $w)->where('id', $d['campaign_id'])->firstOrFail();
                $s = app(WhatsAppNumbers::class)->normalize($w, json_decode($campaign->settings, true));
                abort_unless($s['whatsapp_enabled'] && ($s['whatsapp_number'] ?? null) === $sender->number && (int) ($s['whatsapp_sender_id'] ?? 0) === $sender->id, 422, 'O remetente não corresponde ao modo e aos números da campanha.');
                abort_unless(DB::table('voice_members')->where('campaign_id', $campaign->id)->where('contact_id', $contact->id)->exists(), 422, 'Contato fora da campanha.');
            }
            abort_if(DB::table('wa_messages')->where('account_sid', $c['account_sid'])->where('direction', 'outbound')->where('created_at', '>=', now()->startOfDay())->count() >= $c['daily_limit'], 429, 'Limite diário de homologação atingido.');
            $id = (string) Str::uuid();
            DB::table('wa_messages')->insert(['id' => $id, 'run_id'=>$followup?DB::table('voice_followups')->where('id',$followup)->value('run_id'):null, 'workspace_id' => $w, 'sender_id' => $sender->id, 'contact_id' => $contact->id, 'campaign_id' => $d['campaign_id'] ?? null, 'user_id' => $user, 'template_id' => $template->id, 'direction' => 'outbound', 'idempotency_key' => $d['idempotency_key'], 'request_hash' => $hash, 'account_sid' => $c['account_sid'], 'from_number' => $sender->number, 'to_number' => $contact->phone, 'status' => 'sending', 'variables' => json_encode((object) $d['variables']), 'consent_evidence' => $d['consent_evidence'], 'created_at' => now(), 'updated_at' => now()]);

            return ['row' => DB::table('wa_messages')->find($id), 'created' => true];
        });
        $m = $result['row'];
        if (! $result['created']) {
            return $m;
        }
        app(ConversationInbox::class)->record($m->id);
        // Reservation commits before HTTP. A crash/timeout never triggers an automatic duplicate POST.
        try {
            if ($followup) { app(VoiceFollowups::class)->assertEligible($followup); }
            $latest = DB::table('voice_contacts')->find($m->contact_id);
            if (app(OperationPolicy::class)->get($w)['paused'] || ! $latest->consent || $latest->suppressed_at || app(CadenceReentry::class)->replyBlocks($latest,$m->run_id)) {
                DB::table('wa_messages')->where('id', $m->id)->update(['status' => 'cancelled', 'updated_at' => now()]);

                return DB::table('wa_messages')->find($m->id);
            }
            $r = app(TwilioWhatsAppApi::class)->request('POST', 'https://api.twilio.com/2010-04-01/Accounts/'.$c['account_sid'].'/Messages.json', ['From' => 'whatsapp:'.$m->from_number, 'To' => 'whatsapp:'.$m->to_number, 'ContentSid' => $template->content_sid, 'ContentVariables' => json_encode((object) $d['variables']), 'StatusCallback' => TwilioWhatsAppConnection::BASE.'/status/'.$m->id], true);
            if (! preg_match('/^SM[0-9a-fA-F]{32}$/D', $r['sid'] ?? '') || ($r['account_sid'] ?? '') !== $c['account_sid']) {
                throw new \RuntimeException('twilio_unknown');
            }
            $this->apply($m->id, $r['sid'], $r['status'] ?? 'accepted', null);
        } catch (\Throwable $e) {
            DB::table('wa_messages')->where('id', $m->id)->where('status', 'sending')->update(['status' => str_starts_with($e->getMessage(), 'twilio_rejected_') ? 'failed' : 'unknown', 'error_code' => str_starts_with($e->getMessage(), 'twilio_rejected_') ? substr($e->getMessage(), 16) : null, 'updated_at' => now()]);
        }

        return DB::table('wa_messages')->find($m->id);
    }

    public function apply(string $id, string $sid, string $status, ?string $error): void
    {
        $rank = ['sending' => 0, 'unknown' => 0, 'accepted' => 1, 'scheduled' => 1, 'queued' => 2, 'sending_provider' => 3, 'sent' => 4, 'failed' => 5, 'undelivered' => 5, 'delivered' => 6, 'read' => 7];
        if ($status === 'sending') {
            $status = 'sending_provider';
        }if (! isset($rank[$status])) {
            return;
        }
        DB::transaction(function () use ($id, $sid, $status, $error, $rank) {
            $m = DB::table('wa_messages')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_if($m->provider_sid && $m->provider_sid !== $sid, 409);
            if ($m->status === 'cancelled') {
                return;
            }
            $event = hash('sha256', $id.'|'.$sid.'|'.$status.'|'.$error);
            if (DB::table('wa_events')->where('event_hash', $event)->exists()) {
                return;
            }
            DB::table('wa_events')->insert(['event_hash' => $event, 'message_id' => $id, 'status' => $status, 'created_at' => now()]);
            app(IntegrationEvents::class)->emit($m->workspace_id,'message.status',$id,'message:'.$id.':'.$status,['status'=>$status]);
            if (($rank[$status] ?? 0) >= ($rank[$m->status] ?? 0)) {
                DB::table('wa_messages')->where('id', $id)->update(['provider_sid' => $sid, 'status' => $status, 'error_code' => $error, 'updated_at' => now()]);
            }
        });
    }

    public function reconcile(int $w, string $id, string $sid): object
    {
        $c = app(TwilioWhatsAppConnection::class)->require();
        $m = DB::table('wa_messages')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
        abort_unless($m->account_sid === $c['account_sid'] && $m->direction === 'outbound', 409);
        $r = app(TwilioWhatsAppApi::class)->request('GET', 'https://api.twilio.com/2010-04-01/Accounts/'.$c['account_sid'].'/Messages/'.$sid.'.json');
        abort_unless(($r['sid'] ?? '') === $sid && ($r['account_sid'] ?? '') === $m->account_sid && ($r['from'] ?? '') === 'whatsapp:'.$m->from_number && ($r['to'] ?? '') === 'whatsapp:'.$m->to_number, 422, 'Mensagem não corresponde à conta ou aos números desta tentativa.');
        // Unknown sends must be correlated by their exact callback URL, not only by phone numbers.
        abort_unless($m->provider_sid === $sid || ($r['status_callback'] ?? '') === TwilioWhatsAppConnection::BASE.'/status/'.$m->id,422,'Sem correlação inequívoca. Aguarde o callback assinado; não repita o envio.');
        $this->apply($id,$sid,$r['status'] ?? 'unknown',isset($r['error_code']) ? (string) $r['error_code'] : null);

        return DB::table('wa_messages')->find($id);
    }
}
