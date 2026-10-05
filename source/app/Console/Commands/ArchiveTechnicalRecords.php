<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Services\TechnicalRetention;
use Illuminate\Support\Str;
class ArchiveTechnicalRecords extends Command {
 protected $signature='ma:retention-archive';protected $description='Arquiva somente dados técnicos elegíveis quando a política automática estiver habilitada.';
 public function handle():int{$s=app(TechnicalRetention::class);$p=$s->policy(1);if(!$p['automatic']){$this->info('Política automática desativada.');return self::SUCCESS;}$r=$s->archive(1,null,(string)Str::uuid(),$p['revision'],500,true);$this->info(json_encode($r));return self::SUCCESS;}
}
