<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Test adapter only. No external HTTP, SIP, message or AI call is made here. */
class VoiceLab
{
    public const OUTCOMES = ['answered', 'no_answer', 'busy', 'machine', 'invalid', 'rejected'];
    public const QUALIFICATIONS = ['interested', 'callback', 'not_interested', 'opt_out'];

    public function scope(string $table, int $workspace)
    {
        return DB::table($table)->where('workspace_id', $workspace);
    }

    public function get(string $table, int $workspace, int|string $id): object
    {
        return $this->scope($table, $workspace)->where('id', $id)->firstOrFail();
    }

    public function audit(int $workspace, ?int $user, string $event, int|string $subject, array $data = []): void
    {
        $auditId = DB::table('voice_audit')->insertGetId(['workspace_id' => $workspace, 'user_id' => $user, 'event' => $event, 'subject_id' => (string) $subject, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        if(str_starts_with($event,'calling.')||str_starts_with($event,'inbound.')||str_starts_with($event,'conversation.')) app(IntegrationEvents::class)->emit($workspace,$event,(string)$subject,'audit:'.$auditId,['actor_id'=>$user]);
    }

    public static function phone(string $input): string
    {
        $digits = preg_replace('/\D/', '', $input);
        if (!str_starts_with(trim($input), '+') && preg_match('/^0(?:300|500|800|900)[0-9]{7}$/D', $digits)) $digits = substr($digits, 1);
        if (!str_starts_with(trim($input), '+') && in_array(strlen($digits), [10, 11])) $digits = '55'.$digits;
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $digits)) throw ValidationException::withMessages(['phone' => 'Informe um telefone válido, com DDI e DDD.']);
        return '+'.$digits;
    }

    public function contact(int $workspace, int $user, array $data): array
    {
        return DB::transaction(function () use ($workspace, $user, $data) {
            // Workspace lock serializes imports without overwriting existing consent or opt-outs.
            DB::table('voice_workspaces')->where('id', $workspace)->lockForUpdate()->firstOrFail();
            $phone = self::phone($data['phone']);
            $old = $this->scope('voice_contacts', $workspace)->where('phone', $phone)->first();
            if ($old) return ['contact' => $old, 'duplicate' => true];
            $consent = (bool) ($data['consent'] ?? false);
            if ($consent && empty(trim($data['consent_evidence'] ?? ''))) throw ValidationException::withMessages(['consent_evidence' => 'Registre a evidência da autorização.']);
            $id = DB::table('voice_contacts')->insertGetId([
                'workspace_id' => $workspace, 'name' => $data['name'], 'phone' => $phone,
                'original_phone' => $data['phone'], 'source' => $data['source'], 'crm_contact_id' => $data['crm_contact_id'] ?? null,
                'consent' => $consent, 'consent_evidence' => $consent ? $data['consent_evidence'] : null,
                'consented_at' => $consent ? now() : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($workspace, $user, 'contact.created', $id);
            return ['contact' => $this->get('voice_contacts', $workspace, $id), 'duplicate' => false];
        });
    }

    public function window(array $settings, ?CarbonImmutable $time = null): bool
    {
        $time = ($time ?? CarbonImmutable::now())->setTimezone($settings['timezone']);
        return in_array($time->isoWeekday(), $settings['days'], true)
            && $time->format('H:i') >= $settings['start_time'] && $time->format('H:i') < $settings['end_time'];
    }

    public function reason(object $contact, object $campaign, array $settings): ?string
    {
        if ($campaign->status !== 'testing') return 'Inicie ou retome a simulação da campanha.';
        if ($contact->suppressed_at) return 'Contato excluído das abordagens.';
        if ($contact->replied_at) return 'O contato está em atendimento; a cadência foi interrompida.';
        if ($contact->next_allowed_at && CarbonImmutable::parse($contact->next_allowed_at)->isFuture()) return 'Aguarde o intervalo ou o retorno agendado.';
        if (!$this->window($settings)) return 'Fora dos dias ou horários da campanha.';
        $count = $this->scope('voice_attempts', $campaign->workspace_id)->where('campaign_id', $campaign->id)->where('contact_id', $contact->id)->count();
        if ($count >= $settings['max_attempts']) return 'Limite de tentativas desta campanha atingido.';
        return null;
    }

    public function start(int $workspace, int $user, int $campaignId, ?int $contactId, string $key): object
    {
        return DB::transaction(function () use ($workspace, $user, $campaignId, $contactId, $key) {
            // A single short lock caps the whole shared-VM lab at two simulated calls.
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $campaign = $this->get('voice_campaigns', $workspace, $campaignId);
            $settings = json_decode($campaign->settings, true, flags: JSON_THROW_ON_ERROR);
            $existing = $this->scope('voice_attempts', $workspace)->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_if($existing->campaign_id !== $campaignId || ($contactId && $existing->contact_id !== $contactId) || $existing->user_id !== $user, 409, 'Chave de idempotência já utilizada em outra chamada.');
                return $existing;
            }
            DB::table('voice_attempts')->where('status', 'ringing')->where('expires_at', '<=', now())->update(['status' => 'expired', 'finished_at' => now(), 'updated_at' => now()]);
            abort_if(DB::table('voice_attempts')->where('status', 'ringing')->count() >= 2, 409, 'O laboratório permite no máximo duas chamadas simultâneas.');
            abort_if($this->scope('voice_attempts', $workspace)->where('status', 'ringing')->where('campaign_id', $campaignId)->count() >= $settings['concurrency'], 409, 'Limite simultâneo da campanha atingido.');
            abort_if($this->scope('voice_attempts', $workspace)->where('status', 'ringing')->where('user_id', $user)->exists(), 409, 'Conclua sua chamada atual antes de iniciar outra.');
            app(VoiceLiveQueue::class)->settle();
            abort_if(DB::table('voice_live_reservations')->where('workspace_id',$workspace)->where('user_id',$user)->whereIn('status',VoiceLiveQueue::ACTIVE)->exists(),409,'Conclua sua reserva real.');
            $candidates = $this->scope('voice_contacts', $workspace)->whereIn('id', DB::table('voice_members')->where('campaign_id', $campaignId)->select('contact_id'));
            if ($contactId) $candidates->where('id', $contactId);
            elseif ($settings['mode'] !== 'progressive') abort(422, 'Selecione o contato no modo preview.');
            $contact = null; $reason = 'Nenhum contato elegível nesta campanha.';
            foreach ($candidates->orderBy('id')->cursor() as $candidate) {
                $reason = $this->reason($candidate, $campaign, $settings);
                if (DB::table('voice_live_reservations')->where('workspace_id',$workspace)->where('contact_id',$candidate->id)->whereIn('status',VoiceLiveQueue::ACTIVE)->exists()) $reason='Contato reservado na fila real.';
                if (!$reason && $this->scope('voice_attempts', $workspace)->where('contact_id', $candidate->id)->where('status', 'ringing')->exists()) $reason = 'Contato reservado por outra campanha.';
                if (!$reason) { $contact = $candidate; break; }
            }
            abort_unless($contact, 422, $reason ?? 'Contato não pertence à campanha.');
            $id = (string) Str::uuid();
            DB::table('voice_attempts')->insert(['id' => $id, 'workspace_id' => $workspace, 'campaign_id' => $campaignId, 'contact_id' => $contact->id, 'user_id' => $user, 'idempotency_key' => $key, 'status' => 'ringing', 'simulated' => true, 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($workspace, $user, 'call.simulated.started', $id);
            return $this->get('voice_attempts', $workspace, $id);
        });
    }

    public function stopContact(int $workspace, int $user, int $id, string $type): object
    {
        return DB::transaction(function () use ($workspace, $user, $id, $type) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $this->get('voice_contacts', $workspace, $id);
            $changes = [$type === 'opt_out' ? 'suppressed_at' : 'replied_at' => now(), 'updated_at' => now()];
            if ($type === 'opt_out') $changes['consent'] = false;
            $this->scope('voice_contacts', $workspace)->where('id', $id)->update($changes);
            $this->scope('voice_actions', $workspace)->where('contact_id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'reason' => $type === 'opt_out' ? 'Pedido de interrupção.' : 'Atendimento iniciado.', 'updated_at' => now()]);
            $this->scope('voice_attempts', $workspace)->where('contact_id', $id)->where('status', 'ringing')->update(['status' => 'cancelled', 'finished_at' => now(), 'updated_at' => now()]);
            $this->scope('voice_followups', $workspace)->where('contact_id', $id)->whereIn('status', ['pending', 'blocked'])->update(['status' => 'cancelled', 'reason' => 'Resposta ou pedido de interrupção registrado.', 'updated_at' => now()]);
            $this->audit($workspace, $user, 'contact.'.$type, $id);
            return $this->get('voice_contacts', $workspace, $id);
        });
    }

    public function finish(int $workspace, int $user, string $id, array $data): object
    {
        return DB::transaction(function () use ($workspace, $user, $id, $data) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $call = $this->get('voice_attempts', $workspace, $id);
            abort_unless($call->user_id === $user, 403, 'Somente o atendente desta tentativa pode concluí-la.');
            if ($call->status === 'finished') {
                abort_if($call->outcome !== $data['outcome'] || $call->qualification !== ($data['qualification'] ?? null), 409, 'Resultado já registrado; o histórico não pode ser sobrescrito.');
                return $call;
            }
            abort_unless($call->status === 'ringing' && CarbonImmutable::parse($call->expires_at)->isFuture(), 409, 'A tentativa foi encerrada ou expirou.');
            $campaign = $this->get('voice_campaigns', $workspace, $call->campaign_id);
            $s = json_decode($campaign->settings, true, flags: JSON_THROW_ON_ERROR);
            $qualification = $data['qualification'] ?? null;
            $this->scope('voice_attempts', $workspace)->where('id', $id)->update(['status' => 'finished', 'outcome' => $data['outcome'], 'qualification' => $qualification, 'finished_at' => now(), 'updated_at' => now()]);
            $this->scope('voice_contacts', $workspace)->where('id', $call->contact_id)->update(['next_allowed_at' => now()->addMinutes($s['retry_minutes']), 'updated_at' => now()]);
            if ($qualification === 'opt_out' || $data['outcome'] === 'invalid') {
                $this->stopContact($workspace, $user, $call->contact_id, 'opt_out');
            } elseif ($data['outcome'] === 'answered') {
                $this->stopContact($workspace, $user, $call->contact_id, 'reply');
                if ($qualification === 'callback') $this->scope('voice_contacts', $workspace)->where('id', $call->contact_id)->update(['replied_at' => null, 'next_allowed_at' => CarbonImmutable::parse($data['callback_at'])]);
            } elseif ($data['outcome'] === 'no_answer' && $s['whatsapp_enabled'] && ($s['whatsapp_delivery'] ?? 'simulation') === 'simulation') {
                $missed = $this->scope('voice_attempts', $workspace)->where('campaign_id', $call->campaign_id)->where('contact_id', $call->contact_id)->where('outcome', 'no_answer')->where('created_at', '>=', now()->subHours(24))->count();
                $already = $this->scope('voice_actions', $workspace)->where('campaign_id', $call->campaign_id)->where('contact_id', $call->contact_id)->where('created_at', '>=', now()->subHours(24))->exists();
                if ($missed >= $s['whatsapp_after'] && !$already) {
                    $contact = $this->get('voice_contacts', $workspace, $call->contact_id);
                    $eligible = $contact->consent && $contact->consent_evidence && !$contact->suppressed_at && !$contact->replied_at;
                    DB::table('voice_actions')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'campaign_id' => $call->campaign_id, 'contact_id' => $call->contact_id, 'attempt_id' => $id, 'kind' => 'whatsapp', 'status' => $eligible ? 'pending' : 'blocked', 'settings' => json_encode($s), 'due_at' => now()->addMinutes($s['whatsapp_delay']), 'reason' => $eligible ? null : 'Autorização ausente ou contato indisponível.', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $this->audit($workspace, $user, 'call.simulated.finished', $id, ['outcome' => $data['outcome'], 'qualification' => $qualification]);
            return $this->get('voice_attempts', $workspace, $id);
        });
    }

    public function simulateAction(int $workspace, int $user, string $id): object
    {
        return DB::transaction(function () use ($workspace, $user, $id) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $action = $this->get('voice_actions', $workspace, $id);
            if ($action->status !== 'pending') return $action;
            $contact = $this->get('voice_contacts', $workspace, $action->contact_id);
            $campaign = $this->get('voice_campaigns', $workspace, $action->campaign_id);
            $s = json_decode($action->settings, true, flags: JSON_THROW_ON_ERROR);
            $reason = null;
            if ($contact->suppressed_at || $contact->replied_at || !$contact->consent || !$contact->consent_evidence) $reason = 'Autorização revogada ou atendimento iniciado.';
            elseif ($campaign->status !== 'testing') abort(422, 'Campanha pausada. Retome antes de testar o passo.');
            elseif (!$this->window($s, CarbonImmutable::parse($action->due_at))) $reason = 'O horário previsto está fora da janela da campanha.';
            elseif (empty($s['whatsapp_template_id']) || $workspace !== 1 || !\App\Models\CrmResource::where('kind', 'template')->where('channel', 'whatsapp')->where('external_id', $s['whatsapp_template_id'])->where('active', true)->exists()) $reason = 'O template de WhatsApp está ausente ou foi desativado.';
            // This only evaluates a future step. It never advances a live clock or claims delivery.
            $this->scope('voice_actions', $workspace)->where('id', $id)->update(['status' => $reason ? 'blocked' : 'simulated', 'reason' => $reason ?? 'Condições locais válidas. Nenhuma mensagem enviada; homologação do canal e template pendentes.', 'updated_at' => now()]);
            $this->audit($workspace, $user, 'cadence.simulated', $id, ['eligible' => !$reason, 'evaluation_at' => $action->due_at]);
            return $this->get('voice_actions', $workspace, $id);
        });
    }
}
