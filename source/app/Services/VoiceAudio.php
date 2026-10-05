<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class VoiceAudio
{
    public function configuration(): array
    {
        // Read-only, server-side runtime configuration, outside the public directory.
        if (app()->environment('testing')) {
            return config('voice_audio_test', []);
        }
        $path = storage_path('app/voice-runtime.json');

        return is_readable($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    public function ready(): bool
    {
        $c = $this->configuration();
        if (empty($c['enabled']) || empty($c['event_secret']) || empty($c['sip_password'])) {
            return false;
        }
        try {
            return Http::connectTimeout(1)->timeout(2)->get('http://10.241.50.10:8088/httpstatus')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function expire(): void
    {
        DB::table('voice_audio_sessions')->whereIn('status', ['pending', 'connecting', 'active'])->where('expires_at', '<=', now())->update(['status' => 'expired', 'updated_at' => now()]);
    }

    public function create(int $workspace, int $user, string $key): array
    {
        abort_unless($this->ready(), 503, 'O serviço de áudio está indisponível. Atualize o diagnóstico antes de tentar novamente.');

        return DB::transaction(function () use ($workspace, $user, $key) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            app(VoiceCalling::class)->expire();
            abort_if(DB::table('voice_outbound_calls')->whereNull('capacity_released_at')->exists(), 409, 'Há uma ligação externa reservada ou sem confirmação final.');
            abort_if(DB::table('voice_inbound_calls')->whereNull('capacity_released_at')->exists() || DB::table('voice_live_reservations')->whereIn('status', VoiceLiveQueue::ACTIVE)->exists() || app(VoiceAgentCapacity::class)->busy($workspace,$user), 409, 'Conclua os atendimentos, reservas e tabulações antes do diagnóstico de áudio.');
            $this->expire();
            $existing = DB::table('voice_audio_sessions')->where('workspace_id', $workspace)->where('user_id', $user)->where('idempotency_key', $key)->first();
            $c = $this->configuration();
            if ($existing) {
                abort_unless($existing->status === 'pending' && CarbonImmutable::parse($existing->grant_expires_at)->isFuture(), 409, 'Este teste já foi utilizado ou expirou. Inicie um novo teste.');
                $id = $existing->id;
            } else {
                $active = DB::table('voice_audio_sessions')->whereIn('status', ['pending', 'connecting', 'active']);
                abort_if((clone $active)->count() >= 2, 409, 'Já há dois testes de áudio reservados. Aguarde o encerramento.');
                abort_if((clone $active)->where('user_id', $user)->exists(), 409, 'Encerre seu teste atual antes de iniciar outro.');
                $id = (string) Str::uuid();
                $grant = hash_hmac('sha256', $id, $c['event_secret']);
                DB::table('voice_audio_sessions')->insert(['id' => $id, 'workspace_id' => $workspace, 'user_id' => $user, 'idempotency_key' => $key, 'grant_hash' => hash('sha256', $grant), 'status' => 'pending', 'grant_expires_at' => now()->addSeconds(60), 'expires_at' => now()->addSeconds(150), 'created_at' => now(), 'updated_at' => now()]);
                app(VoiceLab::class)->audit($workspace, $user, 'audio.reserved', $id);
            }

            return [
                'id' => $id, 'server' => 'wss://ma.zyrex.ia.br/voice/ws',
                'aor' => 'sip:'.$c['sip_username'].'@ma.zyrex.ia.br',
                'username' => $c['sip_username'], 'password' => $c['sip_password'],
                'destination' => 'sip:'.hash_hmac('sha256', $id, $c['event_secret']).'@ma.zyrex.ia.br',
                'max_seconds' => 60, 'kind' => 'internal_echo',
            ];
        });
    }

    public function event(array $data): array
    {
        return DB::transaction(function () use ($data) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $session = DB::table('voice_audio_sessions')->where('grant_hash', hash('sha256', $data['token']))->first();
            if (! $session || CarbonImmutable::parse($session->expires_at)->isPast()) {
                return ['allowed' => false];
            }
            $event = $data['event'];
            $channel = $data['channel_id'];
            if ($event === 'start') {
                if ($session->status === 'connecting' && $session->channel_id === $channel) {
                    return ['allowed' => true];
                }
                if ($session->status !== 'pending' || CarbonImmutable::parse($session->grant_expires_at)->isPast()) {
                    return ['allowed' => false];
                }
                $changes = ['status' => 'connecting', 'channel_id' => $channel, 'started_at' => now()];
            } elseif ($event === 'answered') {
                if ($session->channel_id !== $channel) {
                    return ['allowed' => false];
                }
                if ($session->status === 'active') {
                    return ['allowed' => true];
                }
                if ($session->status !== 'connecting') {
                    return ['allowed' => false];
                }
                $changes = ['status' => 'active', 'answered_at' => now()];
            } else {
                if ($session->channel_id !== $channel) {
                    return ['allowed' => false];
                }
                if ($session->status === 'ended') {
                    return ['allowed' => true];
                }
                if (! in_array($session->status, ['connecting', 'active'])) {
                    return ['allowed' => false];
                }
                $changes = ['status' => 'ended', 'ended_at' => now(), 'duration' => min(90, $data['duration'] ?? 0), 'cause' => $data['cause'] ?? null];
            }
            DB::table('voice_audio_sessions')->where('id', $session->id)->update($changes + ['updated_at' => now()]);
            app(VoiceLab::class)->audit($session->workspace_id, $session->user_id, 'audio.'.$event, $session->id, ['kind' => 'internal_echo']);

            return ['allowed' => true];
        });
    }
}
