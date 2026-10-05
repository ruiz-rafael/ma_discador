<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CrmResource;
use App\Services\VoiceLab;
use App\Services\WhatsAppNumbers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VoiceLabController extends Controller
{
    public function __construct(private readonly VoiceLab $lab) {}

    private function workspace(Request $r): int
    {
        $id = $r->user()->voice_workspace_id;
        abort_unless($id && DB::table('voice_workspaces')->where('id', $id)->exists(), 403, 'Seu usuário ainda não foi vinculado ao laboratório.');

        return (int) $id;
    }

    private function decode(object $row): object
    {
        if (isset($row->settings)) {
            $row->settings = json_decode($row->settings, true, flags: JSON_THROW_ON_ERROR);
        }
        if (isset($row->data)) {
            $row->data = json_decode($row->data, true, flags: JSON_THROW_ON_ERROR);
        }

        return $row;
    }

    public function index(Request $r): array
    {
        $w = $this->workspace($r);
        $campaigns = $this->lab->scope('voice_campaigns', $w)->orderByDesc('id')->limit(100)->get()->map(function ($row) {
            $row = $this->decode($row);
            $row->contact_ids = DB::table('voice_members')->where('campaign_id', $row->id)->pluck('contact_id');

            return $row;
        });

        return [
            'workspace' => DB::table('voice_workspaces')->find($w), 'user_id' => $r->user()->id, 'mode' => 'simulation', 'max_concurrency' => 2,
            'campaigns' => $campaigns,
            'can_manage_journeys' => in_array($r->user()->voice_role, ['admin', 'supervisor']),
            'journey_lists' => app(\App\Services\VoiceAudience::class)->catalog($w),
            'journeys' => app(\App\Services\VoiceJourneyOverview::class)->get($w),
            'contacts' => $this->lab->scope('voice_contacts', $w)->orderByDesc('id')->limit(500)->get(),
            'attempts' => $this->lab->scope('voice_attempts', $w)->orderByDesc('created_at')->limit(200)->get(),
            'actions' => $this->lab->scope('voice_actions', $w)->orderByDesc('created_at')->limit(200)->get()->map(fn ($row) => $this->decode($row)),
            'audit' => $this->lab->scope('voice_audit', $w)->orderByDesc('id')->limit(100)->get()->map(fn ($row) => $this->decode($row)),
            // Existing MA catalog belongs to its original, single workspace. Never expose it to another lab tenant.
            'references' => $w === 1 ? CrmResource::where('active', true)->whereIn('kind', ['campaign', 'segment', 'template'])->orderBy('name')->limit(500)->get() : [],
            'totals' => ['contacts' => $this->lab->scope('voice_contacts', $w)->count(), 'attempts' => $this->lab->scope('voice_attempts', $w)->count(), 'simulated_actions' => $this->lab->scope('voice_actions', $w)->where('status', 'simulated')->count(), 'real_calls' => DB::table('voice_outbound_calls')->where('workspace_id', $w)->whereNotNull('started_at')->count(), 'real_messages' => DB::table('wa_messages')->where('workspace_id', $w)->where('direction', 'outbound')->where(fn ($q) => $q->whereNotNull('provider_sid')->orWhereNotNull('provider_reference'))->count()],
            'whatsapp_senders' => DB::table('wa_senders')->where('workspace_id', $w)->get(['id', 'label', 'number', 'status', 'provider']),
            'whatsapp_qr_templates' => app(\App\Services\WhatsAppQrTemplates::class)->catalog($w),
            'whatsapp_templates' => DB::table('wa_templates')->where('workspace_id', $w)->get(['id', 'name', 'body', 'approval_status']),
            'followups' => DB::table('voice_followups')->where('workspace_id', $w)->orderByDesc('created_at')->limit(100)->get(['id', 'campaign_id', 'contact_id', 'status', 'reason', 'message_id', 'due_at', 'created_at']),
            'activation_pending' => $this->pending(),
        ];
    }

    public function journeys(Request $r): array
    {
        return app(\App\Services\VoiceJourneyOverview::class)->get($this->workspace($r));
    }

    private function pending(): array
    {
        return [
            'Homologar os números de voz e WhatsApp escolhidos; podem ser únicos ou separados.',
            'Conectar a operadora e validar áudio, disponibilidade dos agentes e callbacks.',
            'Conectar a API oficial do WhatsApp e confirmar os templates aprovados.',
            'Validar limites de consumo, permissões e isolamento de toda a aplicação antes do piloto externo.',
        ];
    }

    public function reference(Request $r)
    {
        $this->workspace($r);

        return response()->download(resource_path('references/Projeto_SaaS_Voz_WhatsApp_IA.pdf'));
    }

    public function campaign(Request $r, ?int $id = null): object
    {
        $w = $this->workspace($r);
        $d = $r->validate([
            'name' => 'required|string|max:160', 'revision' => 'sometimes|integer|min:0',
            'contact_ids' => 'present|array|max:500', 'contact_ids.*' => 'integer|distinct',
            'settings' => 'required|array:mode,crm_campaign_id,segment_id,business_number,number_mode,whatsapp_number,whatsapp_sender_id,timezone,days,start_time,end_time,max_attempts,retry_minutes,concurrency,script,whatsapp_enabled,whatsapp_after,whatsapp_delay,whatsapp_template_id,whatsapp_delivery,whatsapp_text,whatsapp_real_template_id,whatsapp_variables,whatsapp_qr_template_id,whatsapp_qr_buttons_confirmed',
            'settings.mode' => 'required|in:preview,progressive',
            'settings.crm_campaign_id' => 'nullable|string|max:160', 'settings.segment_id' => 'nullable|string|max:160',
            'settings.business_number' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/D'],
            'settings.number_mode' => 'sometimes|in:single,separate',
            'settings.whatsapp_number' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/D'],
            'settings.whatsapp_sender_id' => 'nullable|integer|min:1',
            'settings.timezone' => 'required|timezone', 'settings.days' => 'required|array|min:1|max:7', 'settings.days.*' => 'integer|between:1,7|distinct',
            'settings.start_time' => 'required|date_format:H:i', 'settings.end_time' => 'required|date_format:H:i|after:settings.start_time',
            'settings.max_attempts' => 'required|integer|between:1,20', 'settings.retry_minutes' => 'required|integer|between:1,10080',
            'settings.concurrency' => 'required|integer|between:1,2', 'settings.script' => 'nullable|string|max:4000',
            'settings.whatsapp_enabled' => 'required|boolean', 'settings.whatsapp_after' => 'required|integer|between:1,20',
            'settings.whatsapp_delay' => 'required|integer|between:0,1440', 'settings.whatsapp_template_id' => 'nullable|string|max:160',
            'settings.whatsapp_delivery' => 'sometimes|in:simulation,automatic',
            'settings.whatsapp_text' => 'nullable|string|max:4000',
            'settings.whatsapp_real_template_id' => 'nullable|uuid',
            'settings.whatsapp_qr_template_id' => 'nullable|uuid',
            'settings.whatsapp_qr_buttons_confirmed' => 'sometimes|boolean',
            'settings.whatsapp_variables' => 'sometimes|array|max:10',
            'settings.whatsapp_variables.*' => 'required|string|max:500',
        ]);
        app(\App\Services\VoicePersonalization::class)->validate($d['settings']['whatsapp_text'] ?? '');
        foreach ($d['settings']['whatsapp_variables'] ?? [] as $value) app(\App\Services\VoicePersonalization::class)->validate($value);
        $d['settings'] = app(WhatsAppNumbers::class)->normalize($w, $d['settings']);
        $automatic = ($d['settings']['whatsapp_delivery'] ?? 'simulation') === 'automatic';
        if ($d['settings']['whatsapp_enabled']) {
            abort_if($d['settings']['whatsapp_after'] > $d['settings']['max_attempts'], 422, 'O limiar de WhatsApp não pode exceder o máximo de tentativas.');
            if ($automatic) {
                abort_unless($w === 1, 403);
                $sender = DB::table('wa_senders')->where('workspace_id', $w)->where('id', $d['settings']['whatsapp_sender_id'] ?? 0)->first();
                abort_unless($sender, 422, 'Selecione um remetente WhatsApp cadastrado.');
                if ($sender->provider === 'qr') {
                    abort_unless(!empty($d['settings']['whatsapp_qr_template_id']) || trim($d['settings']['whatsapp_text'] ?? '') !== '', 422, 'Escreva a mensagem para o WhatsApp conectado por QR Code.');
                } else {
                    $template = DB::table('wa_templates')->where('workspace_id', $w)->where('id', $d['settings']['whatsapp_real_template_id'] ?? '')->first();
                    abort_unless($template, 422, 'Selecione um template Twilio cadastrado na aba WhatsApp.');
                    app(\App\Services\WhatsAppTemplates::class)->variables($template->body, $d['settings']['whatsapp_variables'] ?? []);
                }
            } else {
                abort_unless(!empty($d['settings']['whatsapp_template_id']), 422, 'Selecione o template de simulação.');
            }
        }
        foreach (['crm_campaign_id' => 'campaign', 'segment_id' => 'segment', 'whatsapp_template_id' => 'template'] as $field => $kind) {
            if (empty($d['settings'][$field])) {
                continue;
            }
            $q = CrmResource::where('kind', $kind)->where('external_id', $d['settings'][$field])->where('active', true);
            if ($kind === 'template') {
                $q->where('channel', 'whatsapp');
            }
            abort_unless($w === 1 && $q->exists(), 422, 'Referência ausente, inativa ou de outro workspace: '.$field);
        }

        return DB::transaction(function () use ($r, $w, $d, $id) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $count = $this->lab->scope('voice_contacts', $w)->whereIn('id', $d['contact_ids'])->count();
            abort_unless($count === count($d['contact_ids']), 422, 'Selecione somente contatos deste workspace.');
            $row = ['name' => $d['name'], 'settings' => json_encode($d['settings'], JSON_THROW_ON_ERROR), 'updated_at' => now()];
            if ($id) {
                $old = $this->lab->get('voice_campaigns', $w, $id);
                abort_if(DB::table('voice_followups')->where('campaign_id', $id)->where('status', 'dispatching')->exists() || DB::table('voice_outbound_calls')->where('campaign_id', $id)->whereNull('capacity_released_at')->exists(), 409, 'Aguarde a conclusão das chamadas e envios em andamento.');
                abort_unless(in_array($old->status, ['draft', 'paused']), 409, 'Pause a campanha antes de alterar a configuração.');
                abort_if($this->lab->scope('voice_attempts', $w)->where('campaign_id', $id)->where('status', 'ringing')->where('expires_at', '>', now())->exists(), 409, 'Conclua as tentativas abertas antes de alterar a configuração.');
                abort_unless(isset($d['revision']) && $d['revision'] === $old->revision, 409, 'A campanha mudou. Atualize a página antes de salvar.');
                $ruleChanged = \App\Services\VoiceFollowups::ruleChanged(json_decode($old->settings, true), $d['settings']);
                $this->lab->scope('voice_campaigns', $w)->where('id', $id)->update($row + ['revision' => $old->revision + 1, 'followup_revision' => $old->followup_revision + ($ruleChanged ? 1 : 0)]);
                $this->lab->scope('voice_followups', $w)->where('campaign_id', $id)->whereIn('status', ['pending', 'blocked'])->when(! $ruleChanged, fn ($q) => $q->whereNotIn('contact_id', $d['contact_ids']))->update(['status' => 'cancelled', 'reason' => 'Configuração da campanha alterada.', 'updated_at' => now()]);
                // Pending steps preserve their settings snapshot and are cancelled on configuration edits.
                $this->lab->scope('voice_actions', $w)->where('campaign_id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'reason' => 'Configuração da campanha alterada.', 'updated_at' => now()]);
                DB::table('voice_members')->where('campaign_id', $id)->delete();
            } else {
                $id = DB::table('voice_campaigns')->insertGetId($row + ['workspace_id' => $w, 'created_at' => now()]);
            }
            foreach ($d['contact_ids'] as $contact) {
                DB::table('voice_members')->insert(['campaign_id' => $id, 'contact_id' => $contact]);
            }
            $this->lab->audit($w, $r->user()->id, 'campaign.configured', $id);

            return $this->decode($this->lab->get('voice_campaigns', $w, $id));
        });
    }

    public function transition(Request $r, int $id): object
    {
        $w = $this->workspace($r);
        $d = $r->validate(['status' => 'required|in:testing,paused,completed,cancelled']);

        return DB::transaction(function () use ($r, $w, $id, $d) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $c = $this->lab->get('voice_campaigns', $w, $id);
            abort_if(in_array($c->status, ['completed', 'cancelled']), 409, 'Campanha encerrada. Crie uma nova configuração.');
            if ($d['status'] === 'testing') {
                abort_unless(DB::table('voice_members')->where('campaign_id', $id)->exists(), 422, 'Adicione contatos de teste à campanha.');
            }
            $this->lab->scope('voice_campaigns', $w)->where('id', $id)->update(['status' => $d['status'], 'revision' => $c->revision + 1, 'updated_at' => now()]);
            if (in_array($d['status'], ['completed', 'cancelled'])) {
                $this->lab->scope('voice_followups', $w)->where('campaign_id', $id)->whereIn('status', ['pending', 'blocked'])->when(! $ruleChanged, fn ($q) => $q->whereNotIn('contact_id', $d['contact_ids']))->update(['status' => 'cancelled', 'reason' => 'Campanha encerrada.', 'updated_at' => now()]);
                $this->lab->scope('voice_actions', $w)->where('campaign_id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'reason' => 'Campanha encerrada.', 'updated_at' => now()]);
                $this->lab->scope('voice_attempts', $w)->where('campaign_id', $id)->where('status', 'ringing')->update(['status' => 'cancelled', 'finished_at' => now(), 'updated_at' => now()]);
            }
            $this->lab->audit($w, $r->user()->id, 'campaign.'.$d['status'], $id);

            return $this->decode($this->lab->get('voice_campaigns', $w, $id));
        });
    }

    public function activate(Request $r, int $id)
    {
        $this->lab->get('voice_campaigns', $this->workspace($r), $id);

        return response()->json(['message' => 'O laboratório permite somente simulação.', 'issues' => $this->pending()], 422);
    }

    private function contactRules(): array
    {
        return ['name' => 'required|string|max:160', 'phone' => 'required|string|max:80', 'source' => 'required|string|max:300', 'crm_contact_id' => 'nullable|string|max:160', 'consent' => 'required|boolean', 'consent_evidence' => 'nullable|string|max:2000'];
    }

    public function contact(Request $r): array
    {
        return $this->lab->contact($this->workspace($r), $r->user()->id, $r->validate($this->contactRules()));
    }

    public function import(Request $r): array
    {
        $w = $this->workspace($r);
        $d = $r->validate(['file' => 'required|file|max:512', 'commit' => 'required|boolean', 'source' => 'required|string|max:300', 'consent' => 'required|boolean', 'consent_evidence' => 'nullable|string|max:2000', 'name_column' => 'required|string|max:80', 'phone_column' => 'required|string|max:80', 'delimiter' => ['required', Rule::in([',', ';'])]]);
        abort_if($d['consent'] && empty(trim($d['consent_evidence'] ?? '')), 422, 'Informe a evidência da autorização para a importação.');
        $file = fopen($r->file('file')->getRealPath(), 'r');
        try {
            $headers = fgetcsv($file, 0, $d['delimiter'], '"', '');
            abort_unless($headers, 422, 'CSV vazio.');
            $headers = array_map(fn ($v) => trim(ltrim((string) $v, "\xEF\xBB\xBF")), $headers);
            $name = array_search($d['name_column'], $headers, true);
            $phone = array_search($d['phone_column'], $headers, true);
            abort_if($name === false || $phone === false, 422, 'Confira os nomes das colunas e o separador.');
            $rows = [];
            $errors = [];
            $seen = [];
            $duplicates = 0;
            $line = 1;
            while (($values = fgetcsv($file, 0, $d['delimiter'], '"', '')) !== false) {
                $line++;
                abort_if($line > 501, 422, 'O laboratório aceita até 500 linhas por arquivo.');
                if ($values === [null]) {
                    continue;
                }
                $row = ['name' => trim($values[$name] ?? ''), 'phone' => trim($values[$phone] ?? ''), 'source' => $d['source'], 'consent' => (bool) $d['consent'], 'consent_evidence' => $d['consent_evidence'] ?? null];
                try {
                    Validator::make($row, $this->contactRules())->validate();
                    $normal = VoiceLab::phone($row['phone']);
                    if (isset($seen[$normal]) || $this->lab->scope('voice_contacts', $w)->where('phone', $normal)->exists()) {
                        $duplicates++;

                        continue;
                    }
                    $seen[$normal] = true;
                    $rows[] = $row;
                } catch (ValidationException $e) {
                    $errors[] = ['line' => $line, 'message' => implode(' ', $e->validator->errors()->all())];
                }
            }
        } finally {
            fclose($file);
        }
        $created = 0;
        if ($d['commit']) {
            DB::transaction(function () use ($rows, $w, $r, &$created, &$duplicates) {
                foreach ($rows as $row) {
                    $result = $this->lab->contact($w, $r->user()->id, $row);
                    if ($result['duplicate']) {
                        $duplicates++;
                    } else {
                        $created++;
                    }
                }
                $this->lab->audit($w, $r->user()->id, 'contacts.imported', $w, ['created' => $created, 'duplicates' => $duplicates]);
            });
        }

        return ['valid' => count($rows), 'created' => $created, 'duplicates' => $duplicates, 'invalid' => count($errors), 'errors' => array_slice($errors, 0, 20), 'preview' => array_map(fn ($v) => ['name' => $v['name'], 'phone' => VoiceLab::phone($v['phone'])], array_slice($rows, 0, 10)), 'committed' => (bool) $d['commit']];
    }

    public function stopContact(Request $r, int $id): object
    {
        $d = $r->validate(['type' => 'required|in:opt_out,reply']);

        return $this->lab->stopContact($this->workspace($r), $r->user()->id, $id, $d['type']);
    }

    public function start(Request $r): object
    {
        $d = $r->validate(['campaign_id' => 'required|integer', 'contact_id' => 'nullable|integer', 'idempotency_key' => 'required|string|max:100']);

        return $this->lab->start($this->workspace($r), $r->user()->id, (int) $d['campaign_id'], isset($d['contact_id']) ? (int) $d['contact_id'] : null, $d['idempotency_key']);
    }

    public function finish(Request $r, string $id): object
    {
        $d = $r->validate(['outcome' => ['required', Rule::in(VoiceLab::OUTCOMES)], 'qualification' => ['nullable', 'required_if:outcome,answered', 'prohibited_unless:outcome,answered', Rule::in(VoiceLab::QUALIFICATIONS)], 'callback_at' => 'nullable|required_if:qualification,callback|date|after:now']);

        $w = $this->workspace($r);
        $this->lab->get('voice_attempts', $w, $id);
        abort_if(DB::table('voice_queue_assignments')->where('workspace_id', $w)->where('attempt_id', $id)->exists(), 409, 'Conclua este atendimento na aba Filas e equipe.');
        return $this->lab->finish($w, $r->user()->id, $id, $d);
    }

    public function simulateAction(Request $r, string $id): object
    {
        return $this->lab->simulateAction($this->workspace($r), $r->user()->id, $id);
    }
}
