<?php

namespace App\Console\Commands;

use App\Services\TwilioWhatsAppConnection;
use App\Services\WhatsAppTemplates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncWhatsAppTemplateApprovals extends Command
{
    protected $signature = 'voice:whatsapp:sync-templates';
    protected $description = 'Consulta aprovações pendentes sem publicar ou reenviar templates';

    public function handle(TwilioWhatsAppConnection $connection, WhatsAppTemplates $templates): int
    {
        if (config('discai.portal')) return self::SUCCESS;
        $account = $connection->read();
        if (! $account) return self::SUCCESS;
        $synced = 0;
        $failed = 0;
        // The private connection belongs to workspace 1 in each tenant installation.
        DB::table('wa_templates')->where('workspace_id', 1)
            ->where('account_sid', $account['account_sid'])->whereNotNull('content_sid')
            ->where('state', 'created')
            ->whereIn('approval_status', ['received', 'pending', 'submitting', 'submission_unknown', 'unknown'])
            ->where(fn ($q) => $q->whereNull('synced_at')->orWhere('synced_at', '<=', now()->subMinutes(9)))
            ->chunkById(100, function ($rows) use ($templates, &$synced, &$failed) {
                foreach ($rows as $row) {
                    try {
                        $templates->approval((int) $row->workspace_id, $row->id, false);
                        $synced++;
                    } catch (\Throwable $e) {
                        $failed++;
                        // Keep the previous status and successful-sync time on transport errors.
                        Log::warning('WhatsApp template approval sync failed', ['template_id' => $row->id, 'exception_class' => get_class($e)]);
                    }
                }
            });
        $this->info("Consultados: {$synced}; falhas: {$failed}.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
