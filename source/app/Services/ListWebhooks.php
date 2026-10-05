<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ListWebhooks
{
    public function public(object $h): array
    {
        return ['id' => $h->id, 'name' => $h->name, 'active' => (bool) $h->active, 'revision' => $h->revision, 'mapping' => json_decode($h->mapping, true), 'url' => rtrim(config('app.url'), '/').'/hooks/lists/'.$h->id, 'receipts' => DB::table('ma_list_webhook_receipts')->where('webhook_id', $h->id)->orderByDesc('id')->limit(10)->get(['event_key', 'result', 'created_at'])->map(fn ($r) => ['event_key' => $r->event_key, 'result' => json_decode($r->result, true), 'created_at' => $r->created_at])];
    }
    public function save(int $w, string $kind, int $list, array $d, ?string $id = null): array
    {
        return DB::transaction(function () use ($w, $kind, $list, $d, $id) {
            $s = app(ListContacts::class); $s->lock(); $s->list($w, $kind, $list);
            $schema = $s->settings($w, $kind, $list)['fields'];
            $mapping = $s->mapping($d['mapping'], $schema, true);
            $targets = array_column($mapping, 'target');
            abort_unless(in_array('phone', $targets) || ($kind === 'automation' && in_array('email', $targets)), 422, 'Selecione telefone para listas de voz, ou telefone/e-mail para listas de automação.');
            foreach ($schema as $field) if ($field['required']) abort_unless(in_array('fields.'.$field['key'], $targets), 422, 'Inclua o campo obrigatório '.$field['label'].' no payload.');
            $old = $id ? DB::table('ma_list_webhooks')->where('workspace_id', $w)->where('kind', $kind)->where('list_id', $list)->where('id', $id)->firstOrFail() : null;
            abort_unless(($old?->revision ?? 0) === $d['revision'], 409, 'O webhook mudou. Atualize antes de salvar.');
            abort_if(! $old && DB::table('ma_list_webhooks')->where('workspace_id', $w)->where('kind', $kind)->where('list_id', $list)->count() >= 10, 422, 'Limite de 10 webhooks por lista.');
            $secret = ! $old || ($d['rotate'] ?? false) ? Str::random(64) : null;
            $id ??= (string) Str::uuid();
            $values = ['name' => $d['name'], 'active' => $d['active'], 'revision' => ($old?->revision ?? 0) + 1, 'mapping' => json_encode($mapping), 'updated_at' => now()];
            if ($secret) $values['token_hash'] = hash('sha256', $secret);
            if ($old) DB::table('ma_list_webhooks')->where('id', $id)->update($values);
            else DB::table('ma_list_webhooks')->insert($values + ['id' => $id, 'workspace_id' => $w, 'kind' => $kind, 'list_id' => $list, 'created_at' => now()]);
            return ['webhook' => $this->public(DB::table('ma_list_webhooks')->find($id)), 'token' => $secret];
        });
    }
    public function preview(int $w, string $kind, int $list, array $mapping, array $payload): array
    {
        $s = app(ListContacts::class); $s->list($w, $kind, $list); $schema = $s->settings($w, $kind, $list)['fields'];
        $mapping = $s->mapping($mapping, $schema, true);
        return $s->normalize($kind, $schema, $s->map($payload, $mapping, true));
    }
    public function receive(string $id, string $token, string $eventKey, string $raw): array
    {
        abort_unless(strlen($raw) <= 65536, 413, 'Limite de 64 KB por evento.');
        $check = DB::table('ma_list_webhooks')->where('id', $id)->first();
        abort_unless($check && $token !== '' && strlen($token) <= 128 && hash_equals($check->token_hash, hash('sha256', $token)), 401, 'Token inválido.');
        return DB::transaction(function () use ($id, $token, $eventKey, $raw) {
            $s = app(ListContacts::class); $s->lock();
            $h = DB::table('ma_list_webhooks')->where('id', $id)->first();
            abort_unless($h && $token !== '' && strlen($token) <= 128 && hash_equals($h->token_hash, hash('sha256', $token)), 401, 'Token inválido.');
            abort_unless($h->active, 410, 'Webhook desativado.');
            abort_unless(preg_match('/^[A-Za-z0-9_.:\-]{1,160}$/D', $eventKey), 422, 'Envie um Idempotency-Key único para cada evento.');
            $hash = hash('sha256', $raw);
            $old = DB::table('ma_list_webhook_receipts')->where('webhook_id', $id)->where('event_key', $eventKey)->first();
            if ($old) { abort_unless(hash_equals($old->request_hash, $hash), 409, 'Este Idempotency-Key já foi usado com outro conteúdo.'); return ['duplicate_event' => true] + json_decode($old->result, true); }
            try { $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); } catch (\JsonException) { abort(422, 'JSON inválido.'); }
            abort_unless(is_array($payload) && ! array_is_list($payload), 422, 'Envie um objeto JSON com os dados de um contato.');
            $d = $this->preview($h->workspace_id, $h->kind, $h->list_id, json_decode($h->mapping, true), $payload);
            $result = $s->ingest($h->workspace_id, $h->kind, $h->list_id, $d, false);
            app(Segments::class)->refresh($h->workspace_id,$h->kind,$h->list_id);
            DB::table('ma_list_webhook_receipts')->insert(['webhook_id' => $id, 'event_key' => $eventKey, 'request_hash' => $hash, 'result' => json_encode($result), 'created_at' => now()]);
            return ['duplicate_event' => false] + $result;
        });
    }
}
