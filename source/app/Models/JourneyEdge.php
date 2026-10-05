<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JourneyEdge extends Model { protected $guarded=[]; protected $casts=[]; public function journey(){return $this->belongsTo(Journey::class);} }
