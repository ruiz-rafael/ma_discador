<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One configured WhatsApp account/WABA per workspace deployment; never mixes client WABAs. */
class WhatsAppOnboarding
{
    private const SENDERS = 'https://messaging.twilio.com/v2/Channels/Senders';

    public function settings(int $w): array
    {
        return json_decode(DB::table('wa_onboarding_settings')->where('workspace_id', $w)->value('settings') ?? '{}', true)
            + ['app_id' => '', 'config_id' => '', 'solution_id' => '', 'graph_version' => '', 'enabled' => false];
    }

    private function sender(int $w, int $id): object
    {
        $s = app(WhatsAppMessages::class)->sender($w, $id);
        abort_unless($s->provider === 'twilio', 422, 'Este assistente é para a API oficial.');
        $c = app(TwilioWhatsAppConnection::class)->require();
        abort_if($s->account_sid && $s->account_sid !== $c['account_sid'], 409, 'Este número pertence a outra conta Twilio. Preserve a conta original.');
        $run = DB::table('wa_onboardings')->where('sender_id', $id)->first();
        abort_if($run && $run->account_sid !== $c['account_sid'], 409, 'O cadastro foi iniciado em outra conta Twilio. Restaure a conexão original para continuar.');
        return $s;
    }

    private function api(string $method, string $url, array $data = []): array
    {
        return app(TwilioWhatsAppApi::class)->request($method, $url, $data);
    }

    private function inventory(): array
    {
        // Pagination is constructed locally. Never follow a provider-supplied arbitrary URL.
        $rows = []; $token = null;
        for ($page = 0; $page < 20; $page++) {
            $data = ['Channel' => 'whatsapp', 'PageSize' => 100];
            if ($token) $data['PageToken'] = $token;
            $r = $this->api('GET', self::SENDERS, $data);
            if (!isset($r['senders']) || !is_array($r['senders'])) throw new \RuntimeException('twilio_unknown');
            $rows = array_merge($rows, $r['senders']);
            $next = $r['meta']['next_page_url'] ?? null;
            if (!$next) return $rows;
            parse_str(parse_url($next, PHP_URL_QUERY) ?? '', $query);
            $token = $query['PageToken'] ?? null;
            if (!is_string($token) || strlen($token) > 2000) throw new \RuntimeException('twilio_unknown');
        }
        abort(409, 'Há muitos remetentes para esta consulta. Use o vínculo avançado pelo Sender SID.');
    }

    private function apply(object $s, array $r): object
    {
        abort_unless(preg_match('/^XE[0-9a-fA-F]{32}$/D', $r['sid'] ?? '') && ($r['sender_id'] ?? '') === 'whatsapp:'.$s->number, 422, 'A Twilio retornou uma identidade diferente do número informado.');
        $account = app(TwilioWhatsAppConnection::class)->require()['account_sid'];
        abort_if(isset($r['account_sid']) && $r['account_sid'] !== $account, 409, 'A conta do remetente não corresponde à conexão.');
        return $this->saveSender($s, $r, $account);
    }

    private function saveSender(object $s, array $r, string $account): object
    {
        return DB::transaction(function () use ($s, $r, $account) {
            $locked = DB::table('wa_senders')->where('id', $s->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->provider_sid && $locked->provider_sid !== $r['sid'], 409, 'Este número já tem outro remetente vinculado.');
            abort_if(DB::table('wa_senders')->where('provider_sid', $r['sid'])->where('id', '!=', $s->id)->exists(), 409, 'O remetente já está vinculado a outro cadastro.');
            $run = DB::table('wa_onboardings')->where('sender_id', $s->id)->first();
            $waba = $r['configuration']['waba_id'] ?? null;
            abort_if($run?->waba_id && $waba !== $run->waba_id, 409, 'A conta WhatsApp retornada não corresponde à empresa autorizada.');
            $status = in_array($r['status'] ?? '', ['ONLINE', 'OFFLINE', 'CREATING', 'PENDING_VERIFICATION', 'VERIFYING', 'ONLINE:UPDATING', 'TWILIO_REVIEW', 'DRAFT', 'STUBBED']) ? $r['status'] : 'UNKNOWN';
            DB::table('wa_senders')->where('id', $s->id)->update(['provider_sid' => $r['sid'], 'account_sid' => $account, 'status' => $status, 'synced_at' => now(), 'updated_at' => now()]);
            DB::table('wa_onboardings')->where('sender_id', $s->id)->update(['state' => $status === 'ONLINE' ? 'online' : 'pending', 'updated_at' => now()]);
            return DB::table('wa_senders')->find($s->id);
        });
    }

    public function status(int $w, int $id): array
    {
        $s = $this->sender($w, $id);
        $run = DB::table('wa_onboardings')->where('sender_id', $id)->first();
        return ['sender' => $s, 'settings' => $this->settings($w), 'onboarding' => $run ? ['mode' => $run->mode, 'state' => $run->state, 'expires_at' => $run->expires_at] : null];
    }

    public function discover(int $w, int $id): array
    {
        $s = $this->sender($w, $id);
        $rows = $this->inventory();
        foreach ($rows as $row) {
            if (($row['sender_id'] ?? '') === 'whatsapp:'.$s->number) return ['found' => true, 'sender' => $this->apply($s, $row), 'can_register' => true];
        }
        $eligible = collect($rows)->contains(fn ($row) => ($row['status'] ?? '') === 'ONLINE' && !empty($row['configuration']['waba_id']));
        return ['found' => false, 'can_register' => $eligible, 'message' => $eligible ? 'Conta pronta para cadastrar um número adicional.' : 'Conclua o primeiro cadastro no console Twilio. Depois clique em Buscar número na Twilio.'];
    }

    public function beginEmbedded(int $w, int $id, int $user): array
    {
        $s = $this->sender($w, $id); $settings = $this->settings($w);
        abort_unless($settings['enabled'] && $settings['app_id'] && $settings['config_id'] && $settings['solution_id'] && $settings['graph_version'], 422, 'O administrador precisa habilitar o aplicativo Meta e a parceria Twilio.');
        abort_if($s->provider_sid, 409, 'Número já vinculado. Use Verificar conexão.');
        $c = app(TwilioWhatsAppConnection::class)->require();
        $a = $this->api('GET', 'https://api.twilio.com/2010-04-01/Accounts/'.$c['account_sid'].'.json');
        abort_unless(($a['sid'] ?? '') === $c['account_sid'] && preg_match('/^AC[0-9a-fA-F]{32}$/D', $a['owner_account_sid'] ?? '') && $a['owner_account_sid'] !== $a['sid'], 422, 'Números de clientes exigem uma subconta Twilio dedicada à empresa deste workspace. A conta atual não será substituída.');
        return DB::transaction(function () use ($w, $id, $user, $c, $settings) {
            DB::table('wa_senders')->where('id', $id)->lockForUpdate()->firstOrFail();
            $old = DB::table('wa_onboardings')->where('sender_id', $id)->first();
            abort_if($old && in_array($old->state, ['creating', 'unknown', 'pending', 'online']), 409, 'Existe um cadastro em andamento. Consulte o número antes de abrir outra sessão.');
            $session = (string) Str::uuid();
            DB::table('wa_onboardings')->updateOrInsert(['sender_id' => $id], ['workspace_id' => $w, 'user_id' => $user, 'account_sid' => $c['account_sid'], 'mode' => 'embedded', 'state' => 'authorizing', 'session_id' => $session, 'expires_at' => now()->addMinutes(20), 'waba_id' => null, 'created_at' => now(), 'updated_at' => now()]);
            return ['session_id' => $session, 'settings' => $settings];
        });
    }

    public function register(int $w, int $id, int $user, array $d): array
    {
        $s = $this->sender($w, $id); $c = app(TwilioWhatsAppConnection::class)->require();
        abort_if($s->provider_sid, 409, 'Número já vinculado. Consulte a conexão.');
        $rows = $this->inventory();
        $wabas = collect($rows)->pluck('configuration.waba_id')->filter()->unique()->values()->all();
        if ($d['mode'] === 'own') {
            abort_unless(collect($rows)->contains(fn ($row) => ($row['status'] ?? '') === 'ONLINE' && !empty($row['configuration']['waba_id'])), 422, 'O primeiro número precisa ser cadastrado no console Twilio.');
        } else {
            abort_unless($this->settings($w)['enabled'], 422, 'Cadastro de clientes desabilitado.');
            abort_if($wabas && (count($wabas) !== 1 || $wabas[0] !== $d['waba_id']), 409, 'Esta subconta já pertence a outra conta WhatsApp. Use um workspace e uma subconta dedicados à empresa.');
        }
        // Do not re-create a sender already present at the provider.
        abort_if(collect($rows)->contains(fn ($row) => ($row['sender_id'] ?? '') === 'whatsapp:'.$s->number), 409, 'Número encontrado na Twilio. Clique em Buscar número na Twilio para recuperá-lo.');
        DB::transaction(function () use ($w, $id, $user, $c, $d) {
            $locked = DB::table('wa_senders')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_if($locked->provider_sid, 409, 'Número já vinculado.');
            $old = DB::table('wa_onboardings')->where('sender_id', $id)->first();
            abort_if($old && in_array($old->state, ['creating', 'unknown', 'pending', 'online']), 409, 'Cadastro já solicitado. Consulte a Twilio; não repita a criação.');
            abort_if($old?->last_request_at && $old->last_request_at > now()->subMinute()->toDateTimeString(), 429, 'Aguarde um minuto antes de solicitar outro cadastro.');
            if ($d['mode'] === 'embedded') {
                abort_unless($old && $old->state === 'authorizing' && $old->user_id === $user && $old->account_sid === $c['account_sid'] && hash_equals($old->session_id, $d['session_id'] ?? '') && $old->expires_at > now()->toDateTimeString(), 409, 'A autorização expirou ou pertence a outra sessão. Abra o cadastro Meta novamente.');
            }
            DB::table('wa_onboardings')->updateOrInsert(['sender_id' => $id], ['workspace_id' => $w, 'user_id' => $user, 'account_sid' => $c['account_sid'], 'mode' => $d['mode'], 'state' => 'creating', 'waba_id' => $d['mode'] === 'embedded' ? $d['waba_id'] : null, 'last_request_at' => now(), 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
        });
        $config = $d['mode'] === 'embedded' ? ['waba_id' => $d['waba_id']] : ['verification_method' => $d['verification_method']];
        try {
            $r = $this->api('POST', self::SENDERS, ['sender_id' => 'whatsapp:'.$s->number, 'profile' => ['name' => $d['display_name']], 'configuration' => $config, 'webhook' => ['callback_url' => TwilioWhatsAppConnection::BASE.'/inbound', 'callback_method' => 'POST']]);
            $this->apply($s, $r);
        } catch (\Throwable $e) {
            $rejected = str_starts_with($e->getMessage(), 'twilio_rejected_');
            DB::table('wa_onboardings')->where('sender_id', $id)->update(['state' => $rejected ? 'rejected' : 'unknown', 'updated_at' => now()]);
        }
        return $this->status($w, $id);
    }

    public function verify(int $w, int $id, string $code): array
    {
        $s = $this->sender($w, $id);
        abort_unless($s->provider_sid && $s->status !== 'ONLINE', 422, 'Consulte o cadastro antes de informar o código.');
        $reserved = DB::table('wa_onboardings')->where('sender_id', $id)->where('account_sid', app(TwilioWhatsAppConnection::class)->require()['account_sid'])->where(function ($q) { $q->whereNull('last_request_at')->orWhere('last_request_at', '<=', now()->subMinute()); })->update(['last_request_at' => now(), 'updated_at' => now()]);
        abort_unless($reserved, 429, 'Aguarde um minuto e consulte o estado antes de confirmar outro código.');
        try {
            $this->apply($s, $this->api('POST', self::SENDERS.'/'.$s->provider_sid, ['configuration' => ['verification_code' => $code]]));
        } catch (\Throwable $e) {
            abort(422, str_starts_with($e->getMessage(), 'twilio_rejected_') ? 'A Twilio recusou a confirmação. Confira o código e consulte a conexão.' : 'A confirmação não retornou. Consulte a conexão antes de tentar novamente.');
        }
        return $this->status($w, $id);
    }

    public function webhook(int $w, int $id): array
    {
        $s = $this->sender($w, $id);
        abort_unless($s->provider_sid, 422, 'Vincule o remetente primeiro.');
        // Verify the identity before changing the webhook of an existing sender.
        $this->apply($s, $this->api('GET', self::SENDERS.'/'.$s->provider_sid));
        $this->apply($s, $this->api('POST', self::SENDERS.'/'.$s->provider_sid, ['webhook' => ['callback_url' => TwilioWhatsAppConnection::BASE.'/inbound', 'callback_method' => 'POST']]));
        return $this->status($w, $id);
    }
}
