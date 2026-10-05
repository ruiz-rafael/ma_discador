<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Outbound work queues. All attempts are simulated; this service has no provider adapter. */
class VoiceQueue
{
    public const PRESENCE_SECONDS = 90;

    private function lock(): void
    {
        DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
    }

    private function get(string $table, int $workspace, int|string $id): object
    {
        return app(VoiceLab::class)->get($table, $workspace, $id);
    }

    private function audit(int $w, int $u, string $event, int|string $id, array $data = []): void
    {
        app(VoiceLab::class)->audit($w, $u, 'queue.'.$event, $id, $data);
    }

    public function configure(int $w, int $u, array $d, ?int $id = null): object
    {
        return DB::transaction(function () use ($w, $u, $d, $id) {
            $this->lock();
            $this->expire($w);
            $this->get('voice_campaigns', $w, $d['campaign_id']);
            abort_unless(DB::table('users')->where('voice_workspace_id', $w)->whereIn('id', $d['agent_ids'])->count() === count($d['agent_ids']), 422, 'Selecione atendentes deste workspace.');
            $values = ['name' => $d['name'], 'strategy' => $d['strategy'], 'wrapup_seconds' => $d['wrapup_seconds'], 'updated_at' => now()];
            if ($id) {
                $q = $this->get('voice_queues', $w, $id);
                abort_unless($q->campaign_id === $d['campaign_id'], 422, 'Crie outra fila para mudar de campanha.');
                abort_unless($q->status === 'paused' && $q->revision === ($d['revision'] ?? -1), 409, 'Pause a fila e atualize os dados antes de editar.');
                abort_if(DB::table('voice_queue_assignments')->where('queue_id', $id)->where('status', 'active')->exists(), 409, 'Conclua os atendimentos antes de editar a fila.');
                DB::table('voice_queues')->where('id', $id)->update($values + ['revision' => $q->revision + 1]);
                DB::table('voice_queue_agents')->where('queue_id', $id)->delete();
            } else {
                abort_if(DB::table('voice_queues')->where('workspace_id', $w)->count() >= 100, 422, 'Limite de 100 filas deste laboratório atingido.');
                abort_if(DB::table('voice_queues')->where('campaign_id', $d['campaign_id'])->exists(), 409, 'Esta campanha já tem uma fila.');
                $id = DB::table('voice_queues')->insertGetId($values + ['workspace_id' => $w, 'campaign_id' => $d['campaign_id'], 'created_at' => now()]);
            }
            foreach ($d['agent_ids'] as $agent) {
                DB::table('voice_queue_agents')->insert(['queue_id' => $id, 'user_id' => $agent]);
            }
            $this->audit($w, $u, 'configured', $id);
            return $this->get('voice_queues', $w, $id);
        });
    }

    public function transition(int $w, int $u, int $id, string $status): object
    {
        return DB::transaction(function () use ($w, $u, $id, $status) {
            $this->lock();
            $q = $this->get('voice_queues', $w, $id);
            DB::table('voice_queues')->where('id', $id)->update(['status' => $status, 'revision' => $q->revision + 1, 'updated_at' => now()]);
            $this->audit($w, $u, $status, $id);
            return $this->get('voice_queues', $w, $id);
        });
    }

    public function populate(int $w, int $u, int $id): array
    {
        return DB::transaction(function () use ($w, $u, $id) {
            $this->lock();
            $q = $this->get('voice_queues', $w, $id);
            $contacts = DB::table('voice_contacts')->where('workspace_id', $w)->whereIn('id', DB::table('voice_members')->where('campaign_id', $q->campaign_id)->select('contact_id'))->orderBy('id')->get();
            $added = 0;
            foreach ($contacts as $contact) {
                if (DB::table('voice_queue_items')->where('queue_id', $id)->where('contact_id', $contact->id)->exists()) {
                    continue;
                }
                DB::table('voice_queue_items')->insert(['workspace_id' => $w, 'queue_id' => $id, 'contact_id' => $contact->id, 'available_at' => $contact->next_allowed_at ?? now(), 'created_at' => now(), 'updated_at' => now()]);
                $added++;
            }
            $this->audit($w, $u, 'populated', $id, ['added' => $added]);
            return ['added' => $added];
        });
    }

    public function priority(int $w, int $u, int $id, int $priority): void
    {
        DB::transaction(function () use ($w, $u, $id, $priority) {
            $this->lock();
            $item = $this->get('voice_queue_items', $w, $id);
            abort_unless($item->status === 'waiting', 409, 'A prioridade só pode mudar antes da reserva.');
            DB::table('voice_queue_items')->where('id', $id)->update(['priority' => $priority, 'updated_at' => now()]);
            $this->audit($w, $u, 'priority', $id, ['priority' => $priority]);
        });
    }

    public function presence(int $w, int $u, ?string $status = null, ?string $reason = null, ?array $selection = null, ?string $session = null, bool $setSelection = false): void
    {
        DB::transaction(function () use ($w, $u, $status, $reason, $selection, $session, $setSelection) {
            $this->lock();
            $this->expire($w);
            $old = DB::table('voice_agent_presence')->where('user_id', $u)->first();
            abort_unless(DB::table('users')->where('id',$u)->where('voice_workspace_id',$w)->where('voice_enabled',true)->exists(),403,'Atendente desativado.');
            if($status===null&&$old?->session_id&&$old->session_id!==$session)abort(409,'Esta aba não controla sua disponibilidade.');
            if($old?->session_id && $old->session_id!==$session && $old->status!=='offline' && $old->last_seen_at && CarbonImmutable::parse($old->last_seen_at)->gt(now()->subSeconds(90)))abort(409,'Sua disponibilidade está sendo controlada em outra aba. Fique offline naquela aba antes de usar esta.');
            if($setSelection)AgentAvailability::validateSelection($w,$u,$selection);
            $values = ['workspace_id' => $w, 'last_seen_at' => now(), 'updated_at' => now()];
            if($setSelection)$values['queue_ids']=$selection===null?null:json_encode(array_values($selection));
            if($status!==null)$values['session_id']=$session;

            if ($status !== null) {
                $values += ['status' => $status, 'pause_reason' => $status === 'paused' ? $reason : null];
            }
            if ($old) {
                DB::table('voice_agent_presence')->where('user_id', $u)->update($values);
            } else {
                DB::table('voice_agent_presence')->insert($values + ['user_id' => $u, 'created_at' => now()]);
            }
            if ($status !== null) {
                $this->audit($w, $u, 'presence', $u, ['status' => $status]);
            }
        });
    }

    private function blocked(object $contact, object $queue, array $settings): ?string
    {
        if (! $contact->consent || ! $contact->consent_evidence) return 'Sem autorização registrada.';
        if ($contact->suppressed_at) return 'Contato excluído das abordagens.';
        if ($contact->replied_at) return 'Contato já está em atendimento.';
        if (! DB::table('voice_members')->where('campaign_id', $queue->campaign_id)->where('contact_id', $contact->id)->exists()) return 'Contato removido da campanha.';
        if (DB::table('voice_attempts')->where('campaign_id', $queue->campaign_id)->where('contact_id', $contact->id)->count() >= $settings['max_attempts']) return 'Limite de tentativas atingido.';
        return null;
    }

    public function claim(int $w, int $u, int $id, string $key): array
    {
        return DB::transaction(function () use ($w, $u, $id, $key) {
            $this->lock();
            $this->expire($w);
            $q = $this->get('voice_queues', $w, $id);
            abort_unless(DB::table('voice_queue_agents')->where('queue_id', $id)->where('user_id', $u)->exists(), 403, 'Seu usuário não pertence a esta fila.');
            $old = DB::table('voice_queue_assignments')->where('workspace_id', $w)->where('idempotency_key', $key)->first();
            if ($old) {
                abort_unless($old->user_id === $u && $old->queue_id === $id, 409, 'Identificação usada em outra reserva.');
                return ['assignment' => $old, 'message' => 'Reserva já registrada.'];
            }
            abort_unless($q->status === 'running', 409, 'Inicie a fila antes de solicitar contato.');
            $p = DB::table('voice_agent_presence')->where('user_id', $u)->where('workspace_id', $w)->first();
            abort_unless($p && $p->status === 'available' && $p->last_seen_at && CarbonImmutable::parse($p->last_seen_at)->gt(now()->subSeconds(self::PRESENCE_SECONDS)), 409, 'Fique disponível para receber contatos.');
            abort_if($p->available_after && CarbonImmutable::parse($p->available_after)->isFuture(), 409, 'Aguarde o tempo de pós-atendimento.');
            abort_if(DB::table('voice_queue_assignments')->where('workspace_id', $w)->where('user_id', $u)->where('status', 'active')->exists(), 409, 'Conclua sua reserva atual.');
            abort_if(DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('user_id', $u)->whereNull('capacity_released_at')->exists(), 409, 'Conclua sua ligação real antes de simular a fila.');
            app(VoiceLiveQueue::class)->settle();
            abort_if(DB::table('voice_live_reservations')->where('workspace_id',$w)->where('user_id',$u)->whereIn('status',VoiceLiveQueue::ACTIVE)->exists(),409,'Conclua sua reserva real.');
            $campaign = $this->get('voice_campaigns', $w, $q->campaign_id);
            $s = json_decode($campaign->settings, true, flags: JSON_THROW_ON_ERROR);
            abort_unless($campaign->status === 'testing', 409, 'Inicie a simulação da campanha vinculada.');
            abort_unless(app(VoiceLab::class)->window($s), 422, 'Fora da janela de atendimento da campanha.');
            $candidates = DB::table('voice_queue_items')->where('queue_id', $id)->where('status', 'waiting')->where('available_at', '<=', now());
            if ($q->strategy === 'priority') $candidates->orderByDesc('priority');
            $candidates->orderBy('available_at')->orderBy('id');
            foreach ($candidates->get() as $item) {
                $contact = $this->get('voice_contacts', $w, $item->contact_id);
                if ($reason = $this->blocked($contact, $q, $s)) {
                    DB::table('voice_queue_items')->where('id', $item->id)->update(['status' => 'blocked', 'reason' => $reason, 'updated_at' => now()]);
                    continue;
                }
                if ($contact->next_allowed_at && CarbonImmutable::parse($contact->next_allowed_at)->isFuture()) {
                    DB::table('voice_queue_items')->where('id', $item->id)->update(['available_at' => $contact->next_allowed_at, 'updated_at' => now()]);
                    continue;
                }
                if (DB::table('voice_attempts')->where('workspace_id', $w)->where('contact_id', $contact->id)->where('status', 'ringing')->where('expires_at', '>', now())->exists()
                    || DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('contact_id', $contact->id)->whereNull('capacity_released_at')->exists()) continue;
                if (DB::table('voice_live_reservations')->where('workspace_id',$w)->where('contact_id',$contact->id)->whereIn('status',VoiceLiveQueue::ACTIVE)->exists()) continue;
                $assignmentId = (string) Str::uuid();
                $attempt = app(VoiceLab::class)->start($w, $u, $q->campaign_id, $contact->id, 'queue:'.$assignmentId);
                DB::table('voice_queue_items')->where('id', $item->id)->update(['status' => 'reserved', 'reason' => null, 'updated_at' => now()]);
                DB::table('voice_queue_assignments')->insert(['id' => $assignmentId, 'workspace_id' => $w, 'queue_id' => $id, 'item_id' => $item->id, 'user_id' => $u, 'attempt_id' => $attempt->id, 'idempotency_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
                $this->audit($w, $u, 'claimed', $assignmentId);
                return ['assignment' => $this->get('voice_queue_assignments', $w, $assignmentId), 'message' => 'Atendimento simulado reservado. Nenhuma ligação realizada.'];
            }
            return ['assignment' => null, 'message' => 'Nenhum contato elegível agora. Confira agendamentos, autorizações e reservas.'];
        });
    }

    public function finish(int $w, int $u, string $id, array $d): object
    {
        return DB::transaction(function () use ($w, $u, $id, $d) {
            $this->lock();
            $this->expire($w);
            $a = $this->get('voice_queue_assignments', $w, $id);
            abort_unless($a->user_id === $u, 403, 'Somente o atendente reservado pode concluir.');
            $hash = hash('sha256', json_encode([$d['outcome'], $d['qualification'] ?? null, $d['callback_at'] ?? null, $d['notes'] ?? null], JSON_THROW_ON_ERROR));
            if ($a->status === 'completed') {
                abort_unless(hash_equals($a->completion_hash, $hash), 409, 'Resultado já registrado; não pode ser sobrescrito.');
                return $a;
            }
            abort_unless($a->status === 'active', 409, 'A reserva expirou ou foi encerrada.');
            app(VoiceLab::class)->finish($w, $u, $a->attempt_id, $d);
            $q = $this->get('voice_queues', $w, $a->queue_id);
            $item = $this->get('voice_queue_items', $w, $a->item_id);
            $contact = $this->get('voice_contacts', $w, $item->contact_id);
            $s = json_decode($this->get('voice_campaigns', $w, $q->campaign_id)->settings, true);
            $reason = $this->blocked($contact, $q, $s);
            $done = $contact->replied_at || $contact->suppressed_at || $d['outcome'] === 'invalid';
            DB::table('voice_queue_items')->where('id', $item->id)->update(['status' => $done ? 'done' : ($reason ? 'blocked' : 'waiting'), 'reason' => $reason, 'available_at' => $contact->next_allowed_at ?? now(), 'updated_at' => now()]);
            DB::table('voice_queue_assignments')->where('id', $id)->update(['status' => 'completed', 'completion_hash' => $hash, 'notes' => $d['notes'] ?? null, 'finished_at' => now(), 'updated_at' => now()]);
            DB::table('voice_agent_presence')->where('user_id', $u)->update(['available_after' => now()->addSeconds($q->wrapup_seconds), 'wrapup_queue_id'=>null,'wrapup_source'=>null,'wrapup_token'=>null,'wrapup_allow_early'=>false,'updated_at' => now()]);
            $this->audit($w, $u, 'completed', $id, ['outcome' => $d['outcome']]);
            return $this->get('voice_queue_assignments', $w, $id);
        });
    }

    /** Called under voice_runtime lock. Presence loss ends simulated work only. */
    public function expire(int $w): void
    {
        foreach (DB::table('voice_queue_assignments')->where('workspace_id', $w)->where('status', 'active')->get() as $a) {
            $attempt = DB::table('voice_attempts')->find($a->attempt_id);
            $presence = DB::table('voice_agent_presence')->where('user_id', $a->user_id)->where('workspace_id', $w)->first();
            $item = $this->get('voice_queue_items', $w, $a->item_id);
            $contact = $this->get('voice_contacts', $w, $item->contact_id);
            $stale = ! $presence || ! $presence->last_seen_at || CarbonImmutable::parse($presence->last_seen_at)->lte(now()->subSeconds(self::PRESENCE_SECONDS));
            $ended = $attempt->status !== 'ringing';
            $blocked = ! $contact->consent || $contact->suppressed_at || $contact->replied_at;
            if (! $stale && ! $ended && ! $blocked && CarbonImmutable::parse($attempt->expires_at)->isFuture()) continue;
            if (! $ended) DB::table('voice_attempts')->where('id', $attempt->id)->update(['status' => $blocked ? 'cancelled' : 'expired', 'finished_at' => now(), 'updated_at' => now()]);
            $status = $blocked || ($ended && $attempt->status === 'cancelled') ? 'cancelled' : 'expired';
            DB::table('voice_queue_assignments')->where('id', $a->id)->update(['status' => $status, 'finished_at' => now(), 'updated_at' => now()]);
            DB::table('voice_queue_items')->where('id', $item->id)->update(['status' => $blocked ? 'blocked' : 'waiting', 'reason' => $blocked ? 'Autorização revogada ou atendimento iniciado.' : 'Reserva encerrada; reavaliação antes da próxima tentativa.', 'available_at' => now()->addSeconds(30), 'updated_at' => now()]);
            $this->audit($w, $a->user_id, $status, $a->id);
        }
    }

    public function snapshot(int $w, int $u): array
    {
        return DB::transaction(function () use ($w, $u) {
            $this->lock();
            $this->expire($w);
            $queues = DB::table('voice_queues')->where('workspace_id', $w)->orderBy('id')->get();
            foreach ($queues as $q) {
                $q->agent_ids = DB::table('voice_queue_agents')->where('queue_id', $q->id)->pluck('user_id');
                $q->counts = DB::table('voice_queue_items')->where('queue_id', $q->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            }
            $agents = DB::table('users')->where('voice_workspace_id', $w)->orderBy('id')->get(['id', 'name']);
            foreach ($agents as $agent) {
                $p = DB::table('voice_agent_presence')->where('workspace_id', $w)->where('user_id', $agent->id)->first();
                $active = DB::table('voice_queue_assignments')->where('workspace_id', $w)->where('user_id', $agent->id)->where('status', 'active')->first();
                $online = $p && $p->last_seen_at && CarbonImmutable::parse($p->last_seen_at)->gt(now()->subSeconds(self::PRESENCE_SECONDS));
                $agent->status = ! $online ? 'offline' : ($active ? 'busy' : (($p->status === 'available' && $p->available_after && CarbonImmutable::parse($p->available_after)->isFuture()) ? 'wrapup' : $p->status));
                $agent->desired_status = $p->status ?? 'offline';
                $agent->pause_reason = $p->pause_reason ?? null;
                $agent->available_after = $p->available_after ?? null;
            }
            $current = DB::table('voice_queue_assignments')->where('workspace_id', $w)->where('user_id', $u)->where('status', 'active')->first();
            if ($current) {
                $item = $this->get('voice_queue_items', $w, $current->item_id);
                $current->contact = $this->get('voice_contacts', $w, $item->contact_id);
                $current->attempt = $this->get('voice_attempts', $w, $current->attempt_id);
                $q = $this->get('voice_queues', $w, $current->queue_id);
                $current->script = json_decode($this->get('voice_campaigns', $w, $q->campaign_id)->settings, true)['script'] ?? '';
            }
            return ['mode' => 'simulation', 'user_id' => $u, 'presence_seconds' => self::PRESENCE_SECONDS, 'queues' => $queues, 'agents' => $agents, 'current' => $current,
                'campaigns' => DB::table('voice_campaigns')->where('workspace_id', $w)->get(['id', 'name', 'status']),
                'items' => DB::table('voice_queue_items as i')->join('voice_contacts as c', 'c.id', '=', 'i.contact_id')->where('i.workspace_id', $w)->orderBy('i.available_at')->limit(500)->get(['i.*', 'c.name', 'c.phone']),
                'history' => DB::table('voice_queue_assignments as a')->join('voice_attempts as t', 't.id', '=', 'a.attempt_id')->where('a.workspace_id', $w)->orderByDesc('a.created_at')->limit(100)->get(['a.*', 't.outcome', 't.qualification']),
                'server_time' => now()->toIso8601String()];
        });
    }
}
