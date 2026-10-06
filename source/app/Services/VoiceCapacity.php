<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
/** Admission decisions require the shared voice_runtime row lock. */
class VoiceCapacity {
 public function limit():int{return max(1,min(100,(int)config('voice_capacity.simultaneous_calls',1)));}
 public function full():bool{return DB::table('voice_inbound_calls')->whereNull('capacity_released_at')->count()+DB::table('voice_outbound_calls')->whereNull('capacity_released_at')->count()>=$this->limit();}
}
