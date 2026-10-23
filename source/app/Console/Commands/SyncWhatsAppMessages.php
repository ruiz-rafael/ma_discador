<?php

namespace App\Console\Commands;

use App\Services\WhatsAppProviderSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncWhatsAppMessages extends Command
{
    protected $signature = 'voice:whatsapp:sync-messages';
    protected $description = 'Recupera respostas recentes que não chegaram pelo webhook da Twilio';

    public function handle(WhatsAppProviderSync $sync): int
    {
        try {
            $result = $sync->run();
            $this->info("Consultadas: {$result['seen']}; respostas importadas: {$result['imported']}; envios reconciliados: {$result['reconciled']}.");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            Log::warning('WhatsApp message reconciliation failed', ['exception_class' => get_class($exception)]);
            $this->error('A consulta de recuperação falhou; o webhook continua ativo.');

            return self::FAILURE;
        }
    }
}
