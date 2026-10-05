<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class VoiceReports
{
    public function query(int $w, object $user, array $f)
    {
        $q = DB::table('voice_outbound_calls as c')->leftJoin('voice_contacts as p', 'p.id', '=', 'c.contact_id')->leftJoin('voice_campaigns as g', 'g.id', '=', 'c.campaign_id')->leftJoin('users as u', 'u.id', '=', 'c.user_id')->where('c.workspace_id', $w);
        if (! in_array($user->voice_role, ['admin', 'supervisor'])) {
            $q->where('c.user_id', $user->id);
        }
        foreach (['campaign_id', 'list_id', 'queue_id', 'user_id', 'method', 'status', 'environment', 'disposition_code', 'caller_id'] as $key) {
            if (isset($f[$key]) && $f[$key] !== '') {
                $q->where('c.'.$key, $f[$key]);
            }
        }
        if (! empty($f['phone'])) {
            $q->where('c.destination', 'like', '%'.preg_replace('/[^0-9+]/', '', $f['phone']).'%');
        }
        $tz = $f['timezone'] ?? 'America/Sao_Paulo';
        if (! empty($f['from'])) {
            $q->whereRaw('COALESCE(c.started_at,c.created_at) >= ?', [CarbonImmutable::parse($f['from'], $tz)->startOfDay()->utc()]);
        }
        if (! empty($f['to'])) {
            $q->whereRaw('COALESCE(c.started_at,c.created_at) < ?', [CarbonImmutable::parse($f['to'], $tz)->addDay()->startOfDay()->utc()]);
        }
        if (! empty($f['pending_disposition'])) {
            $q->where(fn ($q) => $q->whereNotNull('c.answered_at')->orWhere('c.status', 'completed'))->whereNull('c.disposition_code');
        }

        return $q;
    }

    public function rows($q)
    {
        return $q->select(['c.id', 'c.user_id', 'c.contact_id', 'c.campaign_id', 'c.queue_id', 'c.list_id', 'c.method', 'c.environment', 'c.destination', 'c.caller_id', 'c.status', 'c.dial_status', 'c.cause', 'c.bill_seconds', 'c.started_at', 'c.answered_at', 'c.ended_at', 'c.created_at', 'c.capacity_released_at', 'c.disposition_revision', 'c.disposition_code', 'c.disposition_label', 'c.human_confirmed', 'c.disposition_notes', 'c.callback_at', 'c.context_snapshot', 'p.name as contact_name', 'g.name as campaign_name', 'u.name as agent_name'])->orderByDesc('c.created_at')->orderBy('c.id');
    }

    public function snapshot(int $w, object $user, array $f): array
    {
        $q = $this->query($w, $user, $f);
        $summary = (clone $q)->selectRaw("count(*) as reservations, sum(case when c.started_at is not null then 1 else 0 end) as attempts, sum(case when c.answered_at is not null or c.status='completed' then 1 else 0 end) as answered, sum(case when c.capacity_released_at is null or c.status='unknown' then 1 else 0 end) as pending, sum(case when (c.answered_at is not null or c.status='completed') and c.disposition_code is null then 1 else 0 end) as unclassified, count(distinct case when c.human_confirmed=true then c.contact_id end) as human_contacts, count(distinct case when c.disposition_code='converted' then c.contact_id end) as converted_contacts, coalesce(sum(c.bill_seconds),0) as conversation_seconds")->first();
        $ids = (clone $q)->select('c.id');
        $costs = DB::table('voice_call_costs')->whereIn('call_id', $ids)->whereNotNull('amount')->selectRaw('currency, sum(amount) as amount, count(*) as priced_legs')->groupBy('currency')->get();
        $rows = $this->rows(clone $q)->paginate(30, ['*'], 'page', (int) ($f['page'] ?? 1));
        foreach ($rows as $row) {
            $row->context_snapshot = json_decode($row->context_snapshot ?? '{}', true);
            foreach (['contact_name', 'campaign_name', 'agent_name'] as $key) {
                if (array_key_exists($key, $row->context_snapshot)) {
                    $row->$key = $row->context_snapshot[$key];
                }
            }$row->costs = DB::table('voice_call_costs')->where('call_id', $row->id)->get(['leg', 'amount', 'currency', 'synced_at']);
        }

        return ['calls' => $rows, 'summary' => $summary, 'costs' => $costs, 'cost_notice' => 'Somente valores confirmados. Ausência de preço não significa custo zero; pernas pendentes não entram na soma.', 'generated_at' => now()->toIso8601String(), 'filters' => $f];
    }

    public function tabulate(int $w, object $user, string $id, array $d): object
    {
        return DB::transaction(function () use ($w, $user, $id, $d) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $call = DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
            abort_unless($call->user_id === $user->id || in_array($user->voice_role, ['admin', 'supervisor']), 403);
            abort_unless($call->capacity_released_at && ! in_array($call->status, ['pending', 'dialing', 'answered', 'unknown']), 422, 'Aguarde o encerramento confirmado pela telefonia.');
            $hash = hash('sha256', json_encode([$id, $user->id, $d['code'], $d['notes'] ?? null, $d['callback_at'] ?? null, $d['correction_reason'] ?? null]));
            $old = DB::table('voice_dispositions')->where('workspace_id', $w)->where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) {
                abort_unless($old->request_hash === $hash, 409, 'Identificação já utilizada.');

                return (object) ['id' => $call->id, 'disposition_revision' => $call->disposition_revision];
            }
            abort_unless($call->disposition_revision === $d['revision'], 409, 'A tabulação mudou. Atualize antes de salvar.');
            if ($call->disposition_revision) {
                abort_unless(! empty(trim($d['correction_reason'] ?? '')), 422, 'Informe o motivo da correção.');
            }
            $code = DB::table('voice_disposition_codes')->where('workspace_id', $w)->where('code', $d['code'])->where('active', true)->firstOrFail();
            abort_if($code->human && ! ($call->answered_at || $call->status === 'completed'), 422, 'A telefonia não confirmou atendimento nesta chamada.');
            abort_if($code->notes_required && empty(trim($d['notes'] ?? '')), 422, 'Esta tabulação exige uma observação.');
            abort_if($code->code === 'callback' && empty($d['callback_at']), 422, 'Informe a data do retorno solicitado.');
            abort_if($code->code !== 'callback' && ! empty($d['callback_at']), 422, 'Retorno só é permitido para a tabulação correspondente.');
            $values = ['code' => $code->code, 'label' => $code->label, 'human' => $code->human, 'notes' => $d['notes'] ?? null, 'callback_at' => empty($d['callback_at']) ? null : CarbonImmutable::parse($d['callback_at'])->utc()];
            DB::table('voice_dispositions')->insert($values + ['workspace_id' => $w, 'call_id' => $id, 'user_id' => $user->id, 'revision' => $call->disposition_revision + 1, 'idempotency_key' => $d['idempotency_key'], 'request_hash' => $hash, 'correction_reason' => $d['correction_reason'] ?? null, 'created_at' => now()]);
            DB::table('voice_outbound_calls')->where('id', $id)->update(['disposition_revision' => $call->disposition_revision + 1, 'disposition_code' => $code->code, 'disposition_label' => $code->label, 'human_confirmed' => $code->human, 'disposition_notes' => $values['notes'], 'callback_at' => $values['callback_at'], 'updated_at' => now()]);
            if ($code->code === 'opt_out') {
                app(VoiceLab::class)->stopContact($w, $user->id, $call->contact_id, 'opt_out');
            }
            if ($code->code === 'invalid') {
                app(VoiceLab::class)->stopContact($w, $user->id, $call->contact_id, 'opt_out');
            }
            app(VoiceLab::class)->audit($w, $user->id, 'calling.tabulated', $id, ['code' => $code->code, 'revision' => $call->disposition_revision + 1]);
            app(VoiceLiveQueue::class)->settle();

            return (object) ['id' => $id, 'disposition_revision' => $call->disposition_revision + 1];
        });
    }

    public static function csvCell(mixed $v): string
    {
        $v = (string) ($v ?? '');

        return preg_match('/^[\s]*[=+@\-\t\r\n]/u',$v) ? "'".$v : $v;
    }
}
