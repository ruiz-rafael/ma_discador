<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

/** A tenant may verify only its assigned lines, against an operator-approved account/WABA. */
class WhatsAppVerification
{
    private const SENDERS = 'https://messaging.twilio.com/v2/Channels/Senders';

    public static function platform(Request $r): bool
    {
        if ($r->user()?->voice_role !== 'admin') return false;
        if ($r->session()->has('discai_identity') || app(\App\Services\Portal\TenantBridge::class)->config()) {
            return $r->session()->get('discai_identity.platform_admin') === true;
        }
        return true; // Standalone installation administrator.
    }

    public function policy(int $w): array
    {
        return app(WhatsAppOnboarding::class)->settings($w)['client_verification'] ?? ['enabled' => false, 'waba_id' => '', 'account_sid' => ''];
    }

    public function configure(int $w, array $d): void
    {
        $c = app(TwilioWhatsAppConnection::class)->read();
        if ($d['enabled']) {
            abort_unless($c, 422, 'Configure a conexão WhatsApp deste ambiente antes de liberar a verificação.');
            $this->inventory($d['waba_id']);
        }
        $settings = app(WhatsAppOnboarding::class)->settings($w);
        $settings['client_verification'] = ['enabled' => $d['enabled'], 'waba_id' => $d['waba_id'] ?? '', 'account_sid' => $c['account_sid'] ?? ''];
        DB::table('wa_onboarding_settings')->updateOrInsert(['workspace_id' => $w], ['settings' => json_encode($settings), 'updated_at' => now(), 'created_at' => now()]);
    }

    public function numbers(int $w): array
    {
        $numbers = [];
        foreach (DB::table('voice_sip_trunks')->where('workspace_id', $w)->where('enabled', true)->get(['number', 'provider']) as $n) {
            $numbers[$n->number] = ['number' => $n->number, 'operator' => $n->provider === 'twilio' ? 'Twilio' : 'Fale Vono', 'ownership' => $n->provider === 'twilio' ? 'twilio' : 'external'];
        }
        foreach (DB::table('voice_managed_numbers')->where('active', true)->pluck('number') as $number) {
            $numbers[$number] = ['number' => $number, 'operator' => 'Twilio', 'ownership' => 'twilio'];
        }
        // Explicitly owned origins only; a verified outbound caller ID is not an assigned DID.
        foreach (DB::table('voice_origins')->where('workspace_id', $w)->where('kind', 'owned')->where('enabled', true)->whereNotNull('verified_at')->whereNotIn('number', DB::table('voice_sip_trunks')->where('workspace_id', $w)->where('enabled', false)->select('number'))->whereNotIn('number', DB::table('voice_managed_numbers')->where('active', false)->select('number'))->pluck('number') as $number) {
            if (!isset($numbers[$number])) $numbers[$number] = ['number' => $number, 'operator' => 'Twilio', 'ownership' => 'twilio'];
        }
        // Older dedicated Twilio SIP deployments keep the assigned DID in the private trunk config.
        $trunk = app(VoiceCallingConfig::class)->trunk();
        $legacyNumber = $trunk['caller_id'] ?? null;
        if ($w === 1 && is_string($legacyNumber) && preg_match('/^\+[1-9][0-9]{7,14}$/D', $legacyNumber)
            && DB::table('voice_inbound_routes')->where('workspace_id', $w)->where('number', $legacyNumber)->where('provider', 'sip_trunk')->exists()
            && !DB::table('voice_sip_trunks')->where('number', $legacyNumber)->where('enabled', false)->exists()
            && !DB::table('voice_managed_numbers')->where('number', $legacyNumber)->where('active', false)->exists()) {
            $numbers[$legacyNumber] = ['number' => $legacyNumber, 'operator' => 'Twilio', 'ownership' => 'twilio'];
        }
        foreach ($numbers as &$n) {
            $s = DB::table('wa_senders')->where('workspace_id', $w)->where('number', $n['number'])->first();
            $n['status'] = $s?->status ?? 'unverified';
            $n['sender_id'] = $s?->id;
            $n['qr_conflict'] = $s && $s->provider !== 'twilio';
        }
        unset($n); ksort($numbers);
        return array_values($numbers);
    }

    private function assigned(int $w, string $number): array
    {
        $n = collect($this->numbers($w))->firstWhere('number', $number);
        abort_unless($n, 404, 'Este número não está disponível na sua empresa.');
        abort_if($n['qr_conflict'], 409, 'Este número usa outra conexão WhatsApp. Solicite à administração a revisão da migração.');
        return $n;
    }

    private function query(int $w, string $number) { return DB::table('wa_number_verifications')->where('workspace_id', $w)->where('number', $number); }
    private function sender(int $w, string $number): ?object { return DB::table('wa_senders')->where('workspace_id', $w)->where('number', $number)->where('provider', 'twilio')->first(); }

    public function reception(int $w, string $number): array
    {
        $r = DB::table('voice_inbound_routes')->where('workspace_id', $w)->where('number', $number)->where('enabled', true)->first();
        if (!$r) return ['ready' => false, 'message' => 'Ative uma fila de entrada para este número antes de solicitar a ligação.'];
        $ready = !app(VoiceCapacity::class)->full() && app(InboundVoice::class)->candidates((object) ['workspace_id' => $w, 'queue_id' => $r->queue_id], $r->provider === 'sip_trunk')->isNotEmpty();
        return ['ready' => $ready, 'message' => $ready ? 'Há atendimento online e áudio conectado na fila de entrada.' : 'Deixe um atendente da fila disponível em Minha operação, com o áudio conectado e capacidade livre.'];
    }

    public function status(int $w, string $number): array
    {
        $n = $this->assigned($w, $number); $s = $this->sender($w, $number); $v = $this->query($w, $number)->first();
        $p = $this->policy($w); $c = app(TwilioWhatsAppConnection::class)->status();
        $enabled = $c['configured'] && !empty($p['enabled']) && $p['account_sid'] === (app(TwilioWhatsAppConnection::class)->read()['account_sid'] ?? null);
        $cooldown = $v?->retry_after && \Carbon\Carbon::parse($v->retry_after)->isFuture();
        $state = $s?->status === 'ONLINE' ? 'online' : ($v?->state ?? ($s?->provider_sid ? 'pending' : 'new'));
        $reception = $this->reception($w, $number);
        return ['number' => $number, 'display_name' => DB::table('voice_workspaces')->where('id', $w)->value('name'), 'operator' => $n['operator'], 'sender_id' => $s?->id, 'status' => $s?->status ?? 'unverified',
            'state' => $state, 'setup_ready' => $enabled, 'reception' => $reception,
            'can_request' => $enabled && $reception['ready'] && !$cooldown && !$s?->provider_sid && in_array($state, ['new', 'rejected', 'rate_limited']),
            'can_verify' => $enabled && !$cooldown && $s?->status === 'PENDING_VERIFICATION' && !in_array($state, ['submitting', 'unknown']),
            'retry_after' => $cooldown ? \Carbon\Carbon::parse($v->retry_after)->toIso8601String() : null,
            'message' => !$enabled ? 'Aguardando a administração DiscAI concluir a autorização da conta WhatsApp. Você não precisa configurar credenciais.' : match ($state) {
                'online' => 'WhatsApp conectado. O número está pronto para configurar suas mensagens.',
                'rate_limited' => 'O provedor limitou os códigos. A DiscAI aplica uma pausa preventiva de 24 horas; esse prazo não garante a liberação pela Meta.',
                'unknown', 'submitting' => 'A solicitação está sem confirmação. Consulte o andamento antes de qualquer nova tentativa.',
                'rejected' => 'O provedor recusou a solicitação. Confira a situação com a administração antes de tentar novamente.',
                'pending' => $s?->status === 'VERIFYING' ? 'Código enviado ao provedor. Consulte o andamento para acompanhar a confirmação.' : 'Solicitação registrada. Atenda a ligação e informe o código. Se ele não chegar, consulte o andamento; um novo envio exige revisão da administração.',
                default => 'Confira o atendimento e solicite uma ligação para ouvir o código de verificação.',
            }];
    }

    private function connection(int $w): array
    {
        $p = $this->policy($w); $c = app(TwilioWhatsAppConnection::class)->require();
        abort_unless(!empty($p['enabled']) && $p['account_sid'] === $c['account_sid'] && preg_match('/^[0-9]{5,40}$/D', $p['waba_id'] ?? ''), 409, 'A administração precisa concluir a autorização WhatsApp desta empresa.');
        return $p;
    }

    private function inventory(string $waba): array
    {
        $rows = []; $token = null;
        for ($i = 0; $i < 20; $i++) {
            $r = app(TwilioWhatsAppApi::class)->request('GET', self::SENDERS, ['Channel' => 'whatsapp', 'PageSize' => 100] + ($token ? ['PageToken' => $token] : []));
            abort_unless(isset($r['senders']) && is_array($r['senders']), 502, 'Não foi possível conferir a conta WhatsApp.');
            $rows = array_merge($rows, $r['senders']);
            if (empty($r['meta']['next_page_url'])) break;
            parse_str(parse_url($r['meta']['next_page_url'], PHP_URL_QUERY) ?? '', $q); $token = $q['PageToken'] ?? null;
            abort_unless(is_string($token) && strlen($token) <= 2000 && $i < 19, 502, 'Consulta incompleta. Procure a administração.');
        }
        $wabas = collect($rows)->pluck('configuration.waba_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();
        abort_unless($wabas === [$waba], 409, 'A conta WhatsApp precisa ser conferida pela administração. Nenhuma ligação foi solicitada.');
        abort_unless(collect($rows)->contains(fn ($r) => ($r['status'] ?? '') === 'ONLINE' && (string) ($r['configuration']['waba_id'] ?? '') === $waba), 409, 'A primeira ativação da conta WhatsApp ainda depende da administração.');
        return $rows;
    }

    private function apply(int $w, string $number, array $r, array $p): void
    {
        abort_unless(preg_match('/^XE[0-9a-fA-F]{32}$/D', $r['sid'] ?? '') && ($r['sender_id'] ?? '') === 'whatsapp:'.$number && (string) ($r['configuration']['waba_id'] ?? '') === $p['waba_id'] && (!isset($r['account_sid']) || $r['account_sid'] === $p['account_sid']), 409, 'O provedor retornou um vínculo diferente do autorizado. Procure a administração.');
        DB::transaction(function () use ($w, $number, $r, $p) {
            DB::table('voice_workspaces')->where('id', $w)->lockForUpdate()->firstOrFail();
            $n = $this->assigned($w, $number); $s = $this->sender($w, $number);
            abort_if($s && (($s->account_sid && $s->account_sid !== $p['account_sid']) || ($s->provider_sid && $s->provider_sid !== $r['sid'])), 409, 'Este número já possui outro vínculo.');
            abort_if(DB::table('wa_senders')->where('provider_sid', $r['sid'])->where('number', '!=', $number)->exists(), 409);
            $values = ['account_sid' => $p['account_sid'], 'provider_sid' => $r['sid'], 'status' => $r['status'] ?? 'UNKNOWN', 'synced_at' => now(), 'updated_at' => now()];
            if ($s) DB::table('wa_senders')->where('id', $s->id)->update($values);
            else DB::table('wa_senders')->insert($values + ['workspace_id' => $w, 'number' => $number, 'label' => $number.' · WhatsApp', 'provider' => 'twilio', 'ownership' => $n['ownership'], 'created_at' => now()]);
            $run = $this->query($w, $number)->first();
            $limited = $run?->state === 'rate_limited' && $run->retry_after && \Carbon\Carbon::parse($run->retry_after)->isFuture();
            $this->query($w, $number)->update(['state' => ($r['status'] ?? '') === 'ONLINE' ? 'online' : ($limited ? 'rate_limited' : 'pending'), 'updated_at' => now()]);
        });
    }

    public function inspect(int $w, string $number): array
    {
        $this->assigned($w, $number); $p = $this->connection($w); $s = $this->sender($w, $number);
        if ($s?->provider_sid) {
            abort_unless(!$s->account_sid || $s->account_sid === $p['account_sid'], 409);
            $this->apply($w, $number, app(TwilioWhatsAppApi::class)->sender($s->provider_sid), $p);
        } else {
            foreach ($this->inventory($p['waba_id']) as $row) if (($row['sender_id'] ?? '') === 'whatsapp:'.$number) { $this->apply($w, $number, $row, $p); break; }
        }
        return $this->status($w, $number);
    }

    private function reserve(int $w, string $number, int $user, array $p, string $operation): void
    {
        DB::transaction(function () use ($w, $number, $user, $p, $operation) {
            DB::table('voice_workspaces')->where('id', $w)->lockForUpdate()->firstOrFail();
            $this->assigned($w, $number); $v = $this->query($w, $number)->first();
            $local = $this->sender($w, $number);
            abort_if($local?->account_sid && $local->account_sid !== $p['account_sid'], 409, 'Este número já está vinculado a outra conta. Procure a administração.');
            abort_if($v && ($v->account_sid !== $p['account_sid'] || $v->waba_id !== $p['waba_id']), 409, 'Preserve a conta usada para iniciar a verificação.');
            abort_if($v?->retry_after && \Carbon\Carbon::parse($v->retry_after)->isFuture(), 429, 'Aguarde o intervalo indicado antes de tentar novamente.');
            abort_if($v && in_array($v->state, ['submitting', 'unknown', 'online']), 409, 'Consulte o andamento. A solicitação anterior não será repetida.');
            if ($operation === 'request') {
                abort_if($this->sender($w, $number)?->provider_sid || $v?->state === 'pending', 409, 'Já existe um cadastro. Consulte o andamento.');
                $legacy = $this->sender($w, $number);
                abort_if($legacy && DB::table('wa_onboardings')->where('sender_id', $legacy->id)->whereIn('state', ['creating', 'unknown', 'pending', 'online'])->exists(), 409, 'A administração já iniciou este cadastro. Consulte o andamento.');
            }
            $this->query($w, $number)->updateOrInsert(['workspace_id' => $w, 'number' => $number], ['user_id' => $user, 'account_sid' => $p['account_sid'], 'waba_id' => $p['waba_id'], 'state' => 'submitting', 'operation' => $operation, 'provider_error' => null, 'retry_after' => now()->addMinute(), 'created_at' => $v?->created_at ?? now(), 'updated_at' => now()]);
        });
    }

    private function failed(int $w, string $number, \Throwable $e): void
    {
        $limited = $e instanceof TwilioWhatsAppException && ($e->httpStatus === 429 || $e->providerCode === 63114);
        $rejected = str_starts_with($e->getMessage(), 'twilio_rejected_');
        $this->query($w, $number)->update(['state' => $limited ? 'rate_limited' : ($rejected ? 'rejected' : 'unknown'), 'provider_error' => $e instanceof TwilioWhatsAppException ? $e->providerCode : null, 'retry_after' => $limited ? now()->addDay() : now()->addMinute(), 'updated_at' => now()]);
    }

    public function request(int $w, string $number, int $user, string $name): array
    {
        $n = $this->assigned($w, $number); $p = $this->connection($w);
        foreach ($this->inventory($p['waba_id']) as $row) if (($row['sender_id'] ?? '') === 'whatsapp:'.$number) { $this->apply($w, $number, $row, $p); return $this->status($w, $number); }
        if ($n['ownership'] === 'twilio') {
            $r = app(TwilioWhatsAppApi::class)->request('GET', 'https://api.twilio.com/2010-04-01/Accounts/'.$p['account_sid'].'/IncomingPhoneNumbers.json', ['PhoneNumber' => $number, 'PageSize' => 20]);
            abort_unless(collect($r['incoming_phone_numbers'] ?? [])->contains(fn ($n) => ($n['phone_number'] ?? '') === $number && ($n['account_sid'] ?? '') === $p['account_sid'] && ($n['capabilities']['voice'] ?? false) === true), 409, 'A operadora não confirmou o recebimento de chamadas deste número.');
        }
        abort_unless($this->reception($w, $number)['ready'], 409, 'Prepare o atendimento receptivo antes de solicitar a ligação.');
        $this->reserve($w, $number, $user, $p, 'request');
        try {
            $r = app(TwilioWhatsAppApi::class)->request('POST', self::SENDERS, ['sender_id' => 'whatsapp:'.$number, 'configuration' => ['waba_id' => $p['waba_id'], 'verification_method' => 'voice'], 'profile' => ['name' => $name], 'webhook' => ['callback_url' => TwilioWhatsAppConnection::base().'/inbound', 'callback_method' => 'POST']]);
            $this->apply($w, $number, $r, $p);
        } catch (\Throwable $e) { $this->failed($w, $number, $e); }
        return $this->status($w, $number);
    }

    public function verify(int $w, string $number, int $user, string $code): array
    {
        $this->assigned($w, $number); $p = $this->connection($w);
        $this->inspect($w, $number); $s = $this->sender($w, $number);
        if ($s?->status === 'ONLINE') return $this->status($w, $number);
        abort_unless($s?->status === 'PENDING_VERIFICATION', 409, 'Este número não está aguardando um código. Consulte o andamento.');
        $this->reserve($w, $number, $user, $p, 'verify');
        try {
            $r = app(TwilioWhatsAppApi::class)->request('POST', self::SENDERS.'/'.$s->provider_sid, ['configuration' => ['verification_code' => $code]]);
            $this->apply($w, $number, $r, $p);
        } catch (\Throwable $e) { $this->failed($w, $number, $e); }
        return $this->status($w, $number);
    }
}
