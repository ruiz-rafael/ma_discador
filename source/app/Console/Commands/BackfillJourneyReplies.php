<?php
namespace App\Console\Commands;
use App\Services\JourneyReplyAttribution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillJourneyReplies extends Command
{
    protected $signature='ma:backfill-journey-replies';
    protected $description='Classifica respostas já recebidas; não envia mensagens nem inicia chamadas.';
    public function handle(JourneyReplyAttribution $service): int
    {
        $count=0;
        DB::table('wa_messages')->where('direction','inbound')->whereNull('reply_attribution')->orderBy('id')->chunkById(200,function($rows)use($service,&$count){
            foreach($rows as $m){$service->record($m->id);$count++;}
        });
        $this->info("Respostas classificadas: $count");return self::SUCCESS;
    }
}
