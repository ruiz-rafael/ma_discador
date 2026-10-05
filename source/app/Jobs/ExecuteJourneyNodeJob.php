<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\JourneyExecutionEngine;
class ExecuteJourneyNodeJob implements ShouldQueue {
 use Queueable;
 public int $tries=3;
 public int $timeout=50;
 public function __construct(public int $tokenId){}
 public function handle(JourneyExecutionEngine $engine):void {$engine->execute($this->tokenId);}
}
