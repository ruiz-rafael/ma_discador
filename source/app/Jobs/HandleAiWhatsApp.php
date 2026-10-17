<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class HandleAiWhatsApp implements ShouldQueue {
 use Queueable;
 public int $tries=1;
 public int $timeout=90;
 public function __construct(public string $messageId){}
 public function handle():void {app(\App\Services\AiWhatsApp::class)->run($this->messageId);}
}
