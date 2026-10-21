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

    private function validate(array $d): array
    {
        $d = Validator::make($d, ['name' => ['required', 'regex:/^[a-z][a-z0-9_]{2,99}$/D'], 'language' => 'required|in:pt_BR,en,es', 'category' => 'required|in:MARKETING,UTILITY', 'body' => 'required|string|max:1024', 'variables' => 'present|array|max:10',
            'buttons' => 'sometimes|array|max:10', 'buttons.*' => 'required|array:type,title,action,url,phone',
            'buttons.*.type' => 'required|in:reply,url,phone', 'buttons.*.title' => 'required|string|max:20',
            'buttons.*.action' => 'sometimes|in:reply,opt_out', 'buttons.*.url' => 'sometimes|nullable|string|max:2000',
            'buttons.*.phone' => 'sometimes|nullable|string|max:20'])->validate();
        $this->variables($d['body'], $d['variables']);
        $buttons = []; $titles = [];
        foreach ($d['buttons'] ?? [] as $b) {
            $title = trim($b['title']);
            abort_if($title === '' || preg_match('/[\x00-\x1f\x7f{}]/u', $title), 422, 'O título do botão deve ser fixo, sem quebras de linha ou variáveis.');
            $key = mb_strtolower($title);
            abort_if(in_array($key, $titles, true), 422, 'Use títulos diferentes para cada botão.');
            $titles[] = $key;
            $button = ['type' => $b['type'], 'title' => $title];
            if ($b['type'] === 'reply') {
                $button['action'] = $b['action'] ?? 'reply';
            } elseif ($b['type'] === 'url') {
                $url = trim($b['url'] ?? '');
                $host = parse_url($url, PHP_URL_HOST);
                abort_unless(filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' && is_string($host) && str_contains($host, '.') && !parse_url($url, PHP_URL_USER) && !str_contains($url, '{') && !str_contains($url, '}'), 422, 'Informe um link HTTPS completo e fixo para o botão.');
                $button['url'] = $url;
            } else {
                abort_unless(preg_match('/^\+[1-9][0-9]{7,14}$/D', $b['phone'] ?? ''), 422, 'Informe o telefone do botão com +, país, DDD e número.');
                $button['phone'] = $b['phone'];
            }
            $buttons[] = $button;
        }
        $replyCount = count(array_filter($buttons, fn ($b) => $b['type'] === 'reply'));
        abort_if(count(array_filter($buttons, fn ($b) => ($b['action'] ?? '') === 'opt_out')) > 1, 422, 'Use apenas um botão para interromper abordagens.');
        abort_if($replyCount > 0 && $replyCount !== count($buttons), 422, 'Escolha respostas rápidas ou botões de link e telefone neste template.');
        if ($buttons && !$replyCount) {
            abort_if(count(array_filter($buttons, fn ($b) => $b['type'] === 'url')) > 2 || count(array_filter($buttons, fn ($b) => $b['type'] === 'phone')) > 1, 422, 'Use até dois links e um telefone por template.');
            abort_if(mb_strlen($d['body']) > 640, 422, 'Templates com link ou telefone aceitam até 640 caracteres no texto.');
        }
        $d['buttons'] = $buttons;
        return $d;
    }

    private function stored(array $d): array
    {
        return array_replace($d, ['variables' => json_encode((object) $d['variables']), 'buttons' => json_encode($d['buttons'])]);
    }

    public function create(int $w, array $d): object
    {
        $d = $this->validate($d);
        abort_if(DB::table('wa_templates')->where('workspace_id', $w)->where('name', $d['name'])->where('language', $d['language'])->exists(), 409, 'Já existe um template com esse nome e idioma.');
        $id = (string) Str::uuid();
        DB::table('wa_templates')->insert(['id' => $id, 'workspace_id' => $w] + $this->stored($d) + ['created_at' => now(), 'updated_at' => now()]);

        return $this->get($w, $id);
    }

    public function update(int $w, string $id, array $d): object
    {
        $d = $this->validate($d);
        DB::transaction(function () use ($w, $id, $d) {
            $t = DB::table('wa_templates')->where('workspace_id', $w)->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($t->state, ['draft', 'rejected_request']) && !$t->content_sid, 409, 'Este template já foi publicado. Crie uma cópia para alterar o conteúdo.');
            abort_if(DB::table('wa_templates')->where('workspace_id', $w)->where('name', $d['name'])->where('language', $d['language'])->where('id', '!=', $id)->exists(), 409, 'Já existe um template com esse nome e idioma.');
            DB::table('wa_templates')->where('id', $id)->update($this->stored($d) + ['updated_at' => now()]);
        });
        return $this->get($w, $id);
    }

    public function buttons(object $t): array
    {
        return is_array($t->buttons ?? null) ? $t->buttons : json_decode($t->buttons ?? '[]', true);
    }

    public function messageButtons(object $t): array
    {
        $buttons = $this->buttons($t);
        foreach ($buttons as $i => &$b) {
            $b['label'] = $b['title'];
            if ($b['type'] === 'reply') $b['id'] = $b['action'] === 'opt_out' ? 'discai_opt_out' : 'discai_reply:'.$t->id.':'.$i;
        }
        return $buttons;
    }

    public function types(object $t): array
    {
        $buttons = $this->messageButtons($t);
        if (!$buttons) return ['twilio/text' => ['body' => $t->body]];
        $reply = $buttons[0]['type'] === 'reply';
        $actions = [];
        foreach ($buttons as $i => $b) {
            if ($reply) $actions[] = ['title' => $b['title'], 'id' => $b['id']];
            else $actions[] = $b['type'] === 'url' ? ['type' => 'URL', 'title' => $b['title'], 'url' => $b['url']] : ['type' => 'PHONE_NUMBER', 'title' => $b['title'], 'phone' => $b['phone']];
        }
        return [$reply ? 'twilio/quick-reply' : 'twilio/call-to-action' => ['body' => $t->body, 'actions' => $actions]];
    }

    public function submit(int $w, string $id): object
    {
        $t = $this->get($w, $id);
        if (in_array($t->state, ['draft', 'rejected_request'])) $t = $this->publish($w, $id);
        abort_unless($t->state === 'created', 409, 'A publicação está sem confirmação. Consulte ou reconcilie antes de repetir.');
        return $this->approval($w, $id, $t->approval_status === 'not_submitted');
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
            $r = app(TwilioWhatsAppApi::class)->request('POST', 'https://content.twilio.com/v1/Content', ['friendly_name' => $t->name, 'language' => $t->language, 'variables' => (object) json_decode($t->variables, true), 'types' => $this->types($t)]);
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
        abort_unless(($r['sid'] ?? '') === $sid && $t->account_sid === $c['account_sid'] && ($r['account_sid'] ?? '') === $c['account_sid'] && ($r['variables'] ?? []) == json_decode($t->variables, true) && ($r['types'] ?? []) == $this->types($t) && ($r['language'] ?? null) === $t->language && ($r['friendly_name'] ?? null) === $t->name, 422, 'O conteúdo informado não corresponde ao rascunho nesta conta, incluindo seus botões.');
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
