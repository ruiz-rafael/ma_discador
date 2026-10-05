<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
class RunVoiceFollowups extends Command
{
    protected $signature = 'voice:followups:run';
    protected $description = 'Concilia WhatsApp QR e executa passos elegíveis após não atendimento';
    public function handle(): int { app(\App\Services\VoiceFollowups::class)->run(); return self::SUCCESS; }
}
