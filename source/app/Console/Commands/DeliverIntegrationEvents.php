<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\IntegrationEvents;
class DeliverIntegrationEvents extends Command {
 protected $signature='ma:events-deliver {--limit=20}';protected $description='Entrega eventos assinados a integrações configuradas, com tentativas limitadas.';
 public function handle():int{foreach(DB::table('ma_event_deliveries')->whereNull('archived_at')->whereIn('status',['pending','retry','delivering'])->where('next_at','<=',now())->where(fn($q)=>$q->whereNull('locked_until')->orWhere('locked_until','<=',now()))->orderBy('next_at')->limit(min(100,max(1,(int)$this->option('limit'))))->pluck('id') as $id)app(IntegrationEvents::class)->deliver($id);return self::SUCCESS;}
}
