<?php

namespace App\Http\Controllers;

use App\Services\TwilioWhatsAppConnection;
use App\Services\WhatsAppMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Twilio\Security\RequestValidator;

class WhatsAppWebhookController extends Controller
{
    private function verify(Request $r, string $suffix): array
    {
        $c = app(TwilioWhatsAppConnection::class)->require();
        abort_unless($r->getQueryString() === null && str_starts_with($r->header('Content-Type', ''), 'application/x-www-form-urlencoded') && strlen($r->getContent()) <= 32768, 400);
        $parameters = $r->request->all();
        foreach ($parameters as $value) {
            abort_unless(is_string($value), 400);
        }
        abort_unless((new RequestValidator($c['auth_token']))->validate($r->header('X-Twilio-Signature', ''), TwilioWhatsAppConnection::BASE.$suffix, $parameters), 403);
        abort_unless(($parameters['AccountSid'] ?? null) === $c['account_sid'], 403);

        return $c;
    }

    public function status(Request $r, string $id)
    {
        $c = $this->verify($r, '/status/'.$id);
        $d = $r->validate(['MessageSid' => ['required', 'regex:/^SM[0-9a-fA-F]{32}$/D'], 'MessageStatus' => 'required|string|max:32', 'ErrorCode' => 'nullable|string|max:20']);
        $m = DB::table('wa_messages')->where('id', $id)->where('account_sid', $c['account_sid'])->where('direction', 'outbound')->firstOrFail();
        app(WhatsAppMessages::class)->apply($m->id, $d['MessageSid'], $d['MessageStatus'], $d['ErrorCode'] ?? null);

        return response('', 204);
    }

    public function inbound(Request $r)
    {
        $c = $this->verify($r, '/inbound');
        $d = $r->validate(['MessageSid' => ['required', 'regex:/^SM[0-9a-fA-F]{32}$/D'], 'From' => ['required', 'regex:/^whatsapp:\+[1-9][0-9]{7,14}$/D'], 'To' => ['required', 'regex:/^whatsapp:\+[1-9][0-9]{7,14}$/D'], 'Body' => 'nullable|string|max:4096', 'OptOutType' => 'nullable|string|max:20', 'ReferralSourceId'=>'nullable|string|max:200','ReferralSourceUrl'=>'nullable|string|max:2000','ReferralHeadline'=>'nullable|string|max:500','ReferralCtwaClid'=>'nullable|string|max:500']);
        DB::transaction(function () use ($d, $c) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            if (DB::table('wa_messages')->where('provider_sid', $d['MessageSid'])->exists()) {
                return;
            }
            $to = substr($d['To'], 9);
            $from = substr($d['From'], 9);
            $sender = DB::table('wa_senders')->where('number', $to)->where('account_sid', $c['account_sid'])->firstOrFail();
            $contact = DB::table('voice_contacts')->where('workspace_id', $sender->workspace_id)->where('phone', $from)->first();
            $id = (string) Str::uuid();
            DB::table('wa_messages')->insert(['id' => $id, 'workspace_id' => $sender->workspace_id, 'sender_id' => $sender->id, 'contact_id' => $contact?->id, 'direction' => 'inbound', 'idempotency_key' => 'inbound:'.$d['MessageSid'], 'request_hash' => hash('sha256', $d['MessageSid']), 'account_sid' => $c['account_sid'], 'provider_sid' => $d['MessageSid'], 'from_number' => $from, 'to_number' => $to, 'status' => 'received', 'body' => $d['Body'] ?? '', 'created_at' => now(), 'updated_at' => now()]);
            app(\App\Services\JourneyReplyAttribution::class)->record($id);
            app(\App\Services\ConversationInbox::class)->record($id,array_filter(['source'=>!empty($d['ReferralSourceId'])?'click_to_whatsapp':null,'source_id'=>$d['ReferralSourceId']??null,'source_url'=>$d['ReferralSourceUrl']??null,'headline'=>$d['ReferralHeadline']??null,'ctwa_clid'=>$d['ReferralCtwaClid']??null]));
            if ($contact) {
                $stop = ($d['OptOutType'] ?? '') === 'STOP' || in_array(mb_strtoupper(trim($d['Body'] ?? '')), ['STOP', 'SAIR', 'PARAR', 'CANCELAR']);
                DB::table('voice_contacts')->where('id', $contact->id)->update([$stop ? 'suppressed_at' : 'replied_at' => now(), 'updated_at' => now()]);
                DB::table('voice_followups')->where('workspace_id', $sender->workspace_id)->where('contact_id', $contact->id)->whereIn('status', ['pending', 'blocked'])->update(['status' => 'cancelled', 'reason' => $stop ? 'Opt-out recebido por WhatsApp.' : 'Resposta recebida por WhatsApp.', 'updated_at' => now()]);
                DB::table('voice_actions')->where('workspace_id', $sender->workspace_id)->where('contact_id', $contact->id)->where('status', 'pending')->update(['status' => 'cancelled', 'reason' => $stop ? 'Opt-out recebido por WhatsApp.' : 'Resposta recebida por WhatsApp.', 'updated_at' => now()]);
            }
        });

        return response('<Response/>',200)->header('Content-Type','application/xml');
    }
}
