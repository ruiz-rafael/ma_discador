<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class VoiceProviderCatalog
{
    public function get(string $path): array
    {
        $c = app(TwilioVoiceConnection::class)->read();
        abort_unless($c, 503, 'Configure a API Twilio.');
        try {
            $r = Http::withBasicAuth($c['api_key'], $c['api_secret'])->acceptJson()->connectTimeout(3)->timeout(10)->withoutRedirecting()->get('https://api.twilio.com/2010-04-01/Accounts/'.$c['account_sid'].'/'.$path);
        } catch (ConnectionException) {
            abort(502, 'A Twilio não respondeu à consulta.');
        }
        abort_unless($r->successful(), 502, 'A Twilio não confirmou a consulta.');

        return $r->json();
    }

    public static function ddd(string $phone): ?string
    {
        return preg_match('/^\+55([1-9][0-9])[0-9]{8,9}$/D', $phone, $m) ? $m[1] : null;
    }

    public function sync(int $w): array
    {
        $c = app(TwilioVoiceConnection::class)->read();
        abort_unless($c, 503);
        $all = [];
        foreach (['IncomingPhoneNumbers' => 'incoming_phone_numbers', 'OutgoingCallerIds' => 'outgoing_caller_ids'] as $resource => $key) {
            $r = $this->get($resource.'.json?PageSize=100');
            abort_if(! empty($r['next_page_uri']), 422, 'Há mais de 100 origens; esta sincronização exige paginação ampliada. Nenhum catálogo foi substituído.');
            foreach ($r[$key] ?? [] as $n) {
                if (($n['account_sid'] ?? '') !== $c['account_sid'] || ! preg_match('/^\+[1-9][0-9]{7,14}$/D', $n['phone_number'] ?? '')) {
                    continue;
                }
                if ($resource === 'IncomingPhoneNumbers' && empty($n['capabilities']['voice'])) {
                    continue;
                }
                if (isset($all[$n['phone_number']])) {
                    continue;
                }
                $all[$n['phone_number']] = ['number' => $n['phone_number'], 'account_sid' => $c['account_sid'], 'provider_sid' => $n['sid'], 'kind' => $resource === 'IncomingPhoneNumbers' ? 'owned' : 'verified'];
            }
        }
        DB::transaction(function () use ($w, $c, $all) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            DB::table('voice_origins')->where('workspace_id', $w)->where('account_sid', $c['account_sid'])->update(['verified_at' => null]);
            foreach ($all as $n) {
                $old = DB::table('voice_origins')->where('workspace_id', $w)->where('account_sid', $c['account_sid'])->where('number', $n['number'])->first();
                $v = $n + ['ddd' => self::ddd($n['number']), 'verified_at' => now(), 'updated_at' => now()];
                if ($old) {
                    DB::table('voice_origins')->where('id', $old->id)->update($v);
                } else {
                    DB::table('voice_origins')->insert($v + ['workspace_id' => $w, 'created_at' => now()]);
                }
            }
        });

        return ['verified' => count($all)];
    }

    public function choose(int $w, object $campaign, object $contact, array $connection): string
    {
        $p = DB::table('voice_campaign_policies')->where('campaign_id', $campaign->id)->first();
        $s = json_decode($campaign->settings, true);
        if (($p->origin_mode ?? 'configured') !== 'same_ddd') {
            abort_unless(($s['business_number'] ?? null) === $connection['caller_id'], 422, 'Número da campanha diferente da origem configurada.');

            return $connection['caller_id'];
        }
        $ddd = self::ddd($contact->phone);
        abort_unless($ddd, 422, 'O destino não tem DDD brasileiro reconhecido.');
        $q = DB::table('voice_origins')->where('workspace_id', $w)->where('account_sid', $connection['account_sid'])->where('ddd', $ddd)->where('enabled', true)->where('verified_at', '>=', now()->subDay());
        if (($s['number_mode'] ?? 'single') === 'single' && ! empty($s['whatsapp_enabled'])) {
            $q->where('number', $s['whatsapp_number'] ?? $s['business_number']);
        }
        $numbers = $q->pluck('number')->all();
        abort_unless($numbers, 422, 'Sem origem autorizada, habilitada e verificada no mesmo DDD. Sincronize os números; não há troca automática de DDD.');

        return $numbers[random_int(0, count($numbers) - 1)];
    }

    public function assertOrigin(object $call): void
    {
        $snapshot = json_decode($call->context_snapshot ?? '{}', true);
        if(!empty($snapshot['queue_channels_id'])){
            $q=DB::table('voice_live_queues')->where('workspace_id',$call->workspace_id)->find($snapshot['queue_channels_id']);
            abort_unless($q&&$q->channels_configured&&$q->calling_method===$call->method,422,'Configuração da fila alterada.');
            abort_unless(app(QueueChannels::class)->origin($q,app(VoiceCallingConfig::class)->connection($call->method)??[])===$call->caller_id,422,'A origem da fila mudou. Faça uma nova reserva.');return;
        }
        if (($snapshot['origin_mode'] ?? 'configured') === 'same_ddd') {
            abort_unless(DB::table('voice_origins')->where('workspace_id', $call->workspace_id)->where('account_sid', $call->provider_account)->where('number', $call->caller_id)->where('enabled', true)->where('verified_at', '>=', now()->subDay())->exists(), 422, 'Origem desativada ou verificação vencida.');
        }
    }

    public function costs(int $w, string $id): array
    {
        $call = DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
        $c = app(TwilioVoiceConnection::class)->read();
        abort_unless($call->method === 'programmable_voice' && $call->channel_id && $c && $call->provider_account === $c['account_sid'], 422, 'Sem correlação inequívoca para consultar custos desta chamada.');
        $legs = ['browser' => $call->channel_id];
        if ($call->provider_child_sid) {
            $legs['phone'] = $call->provider_child_sid;
        }
        $saved = [];
        foreach ($legs as $leg => $sid) {
            abort_unless(preg_match('/^CA[a-fA-F0-9]{32}$/D', $sid), 422);
            $n = $this->get('Calls/'.$sid.'.json');
            abort_unless(($n['sid'] ?? '') === $sid && ($n['account_sid'] ?? '') === $call->provider_account, 502);
            if ($leg === 'phone') {
                abort_unless(($n['parent_call_sid'] ?? '') === $call->channel_id && ($n['to'] ?? '') === $call->destination, 502);
            }
            $price = $n['price'] ?? null;
            $currency = strtoupper($n['price_unit'] ?? '');
            [$amount, $currency] = ChannelCosts::amount($price, $currency);
            $saved[] = ['call_id' => $id, 'provider_sid' => $sid, 'leg' => $leg, 'amount' => $amount, 'currency' => $amount === null ? null : $currency, 'synced_at' => now()];
        }
        DB::transaction(function () use ($saved) { foreach ($saved as $v) {
            $old=DB::table('voice_call_costs')->where('provider_sid',$v['provider_sid'])->lockForUpdate()->first();
            abort_if($old && $old->call_id!==$v['call_id'],409,'Custo vinculado a outra chamada.');
            if($v['amount']===null && $old && $old->amount!==null)continue;
            DB::table('voice_call_costs')->updateOrInsert(['provider_sid' => $v['provider_sid']],$v);
        }});

        return ['legs' => count($saved), 'pending' => count(array_filter($saved,fn ($v) => $v['amount'] === null))];
    }
}
