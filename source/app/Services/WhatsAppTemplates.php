<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WhatsAppTemplates
{
    public function variables(string $body, array $values): array
    {
        preg_match_all('/\{\{([1-9][0-9]?)\}\}/', $body, $m);
        $keys = array_values(array_unique($m[1]));
        sort($keys, SORT_NUMERIC);
        abort_if(str_contains(preg_replace('/\{\{[1-9][0-9]?\}\}/', '', $body), '{{'), 422, 'Use variáveis numeradas: {{1}}, {{2}}.');
        $actual = array_map('strval', array_keys($values));
        sort($actual, SORT_NUMERIC);
        abort_unless($keys === $actual, 422, 'Informe um valor para cada variável do texto, sem variáveis extras.');
        abort_if(count($keys) > 10, 422, 'Máximo de dez variáveis.');
        foreach ($values as $value) {
            abort_unless(is_string($value) && trim($value) !== '' && mb_strlen($value) <= 500, 422, 'Variável inválida.');
        }

return $values;
    }

    public function create(int $w, array $d): object
    {
        $d = Validator::make($d, ['name' => ['required', 'regex:/^[a-z][a-z0-9_]{2,99}$/D'], 'language' => 'required|in:pt_BR,en,es', 'category' => 'required|in:MARKETING,UTILITY', 'body' => 'required|string|max:1024', 'variables' => 'present|array|max:10'])->validate();
        $this->variables($d['body'], $d['variables']);
        abort_if(DB::table('wa_templates')->where('workspace_id', $w)->where('name', $d['name'])->where('language', $d['language'])->exists(), 409, 'Já existe um template com esse nome e idioma.');
        $id = (string) Str::uuid();
        DB::table('wa_templates')->insert(['id' => $id, 'workspace_id' => $w] + array_diff_key($d, ['variables' => 1]) + ['variables' => json_encode((object) $d['variables']), 'created_at' => now(), 'updated_at' => now()]);

        return $this->get($w, $id);
    }

    public function get(int $w, string $id): object
    {
        return DB::table('wa_templates')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
    }

    public function publish(int $w, string $id): object
    {
        $c = app(TwilioWhatsAppConnection::class)->require();
        $t = DB::transaction(function () use ($w, $id, $c) {
            $t = DB::table('wa_templates')->where('workspace_id', $w)->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($t->state, ['draft', 'rejected_request']), 409, 'Template já publicado ou publicação incerta. Consulte/reconcilie antes de repetir.');
            DB::table('wa_templates')->where('id', $id)->update(['state' => 'creating', 'account_sid' => $c['account_sid'], 'updated_at' => now()]);

            return $t;
        });
        try {
            $r = app(TwilioWhatsAppApi::class)->request('POST', 'https://content.twilio.com/v1/Content', ['friendly_name' => $t->name, 'language' => $t->language, 'variables' => (object) json_decode($t->variables, true), 'types' => ['twilio/text' => ['body' => $t->body]]]);
            if (! preg_match('/^HX[0-9a-fA-F]{32}$/D', $r['sid'] ?? '') || ($r['account_sid'] ?? '') !== $c['account_sid']) {
                throw new \RuntimeException('twilio_unknown');
            }DB::table('wa_templates')->where('id', $id)->update(['content_sid' => $r['sid'], 'state' => 'created', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('wa_templates')->where('id', $id)->update(['state' => str_starts_with($e->getMessage(), 'twilio_rejected_') ? 'rejected_request' : 'publication_unknown', 'updated_at' => now()]);
            abort(502, 'Publicação não confirmada. Consulte o estado antes de tentar novamente.');
        }

return $this->get($w, $id);
    }

    public function reconcile(int $w, string $id, string $sid): object
    {
        $c = app(TwilioWhatsAppConnection::class)->require();
        $t = $this->get($w, $id);
        abort_unless(in_array($t->state, ['creating', 'publication_unknown']), 409, 'Reconciliação somente para publicação sem confirmação.');
        $r = app(TwilioWhatsAppApi::class)->content($sid);
        abort_unless(($r['sid'] ?? '') === $sid && $t->account_sid === $c['account_sid'] && ($r['account_sid'] ?? '') === $c['account_sid'] && ($r['variables'] ?? []) == json_decode($t->variables, true) && ($r['types']['twilio/text']['body'] ?? null) === $t->body && ($r['language'] ?? null) === $t->language && ($r['friendly_name'] ?? null) === $t->name, 422, 'O conteúdo informado não corresponde ao rascunho nesta conta.');
        DB::table('wa_templates')->where('id', $id)->whereIn('state', ['creating', 'publication_unknown'])->update(['content_sid' => $sid, 'state' => 'created', 'updated_at' => now()]);

        return $this->get($w, $id);
    }

    public function approval(int $w, string $id, bool $submit = false): object
    {
        $c = app(TwilioWhatsAppConnection::class)->require();
        $t = $this->get($w, $id);
        abort_unless($t->content_sid && $t->account_sid === $c['account_sid'], 409, 'Publique o template nesta conta primeiro.');
        if ($submit) {
            $ok = DB::table('wa_templates')->where('id', $id)->where('approval_status', 'not_submitted')->update(['approval_status' => 'submitting', 'updated_at' => now()]);
            abort_unless($ok, 409, 'Solicitação já iniciada. Atualize a aprovação.');
            try {
                app(TwilioWhatsAppApi::class)->request('POST', 'https://content.twilio.com/v1/Content/'.$t->content_sid.'/ApprovalRequests/whatsapp', ['name' => $t->name, 'category' => $t->category]);
            } catch (\Throwable $e) {
                DB::table('wa_templates')->where('id', $id)->update(['approval_status' => str_starts_with($e->getMessage(), 'twilio_rejected_') ? 'not_submitted' : 'submission_unknown']);
                abort(502, 'Solicitação sem confirmação. Atualize o estado na Twilio.');
            }
        }
        $r = app(TwilioWhatsAppApi::class)->approval($t->content_sid);
        $a = $r['whatsapp'] ?? [];
        $status = strtolower($a['status'] ?? 'unknown');
        if ($status === 'unsubmitted' || ($status === 'unknown' && ! $submit && $t->approval_status === 'not_submitted')) {
            $status = 'not_submitted';
        }if (! in_array($status, ['not_submitted', 'received', 'pending', 'approved', 'rejected', 'paused', 'disabled'])) {
            $status = 'unknown';
        }
        DB::table('wa_templates')->where('id',$id)->update(['approval_status' => $status, 'rejection_reason' => mb_substr((string) ($a['rejection_reason'] ?? ''),0,500), 'synced_at' => now(), 'updated_at' => now()]);

        return $this->get($w,$id);
    }
}
