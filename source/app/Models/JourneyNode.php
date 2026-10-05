<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JourneyNode extends Model { protected $guarded=[]; protected $casts=['settings'=>'array']; public function journey(){return $this->belongsTo(Journey::class);} }
