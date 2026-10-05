<?php

namespace App\Console\Commands;

use App\Services\VoiceCalling;
use App\Services\VoiceCallingConfig;
use App\Services\VoiceLab;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReleaseUnknownVoiceCall extends Command
{
    protected $signature = 'voice:calling:release {id} {--pbx-confirmed-empty}';

    protected $description = 'Libera reserva incerta após conferência operacional do PBX vazio; mantém resultado desconhecido.';

    public function handle(VoiceCallingConfig $c): int
    {
        if (! $this->option('pbx-confirmed-empty') || $c->installed()) {
            $this->error('Bloqueie novas ligações e confirme o PBX sem canais antes de reconciliar.');

            return self::FAILURE;
        }

        return DB::transaction(function () {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            app(VoiceCalling::class)->expire();
            $q = DB::table('voice_outbound_calls')->where('id', $this->argument('id'))->where('method', 'sip_trunk')->where('status', 'unknown')->whereNull('capacity_released_at');
            $m = $q->first();
            if (! $m) {
                $this->error('Reserva incerta não encontrada.');

                return self::FAILURE;
            }$q->update(['capacity_released_at' => now(), 'updated_at' => now()]);
            app(VoiceLab::class)->audit($m->workspace_id, $m->user_id, 'calling.capacity_reconciled', $m->id, ['source' => 'operator_confirmed_empty_pbx']);
            $this->info('Capacidade liberada. O resultado da chamada permanece sem confirmação.');

            return self::SUCCESS;
        });
    }
}
