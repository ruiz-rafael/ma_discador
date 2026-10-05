<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JourneyNodeLog extends Model { protected $guarded=[]; protected $casts=['payload'=>'array','response'=>'array','executed_at'=>'datetime']; public $timestamps=false; public function journey(){return $this->belongsTo(Journey::class);} }
