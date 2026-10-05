<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JourneySubscriber extends Model { protected $guarded=[]; protected $casts=['entered_at'=>'datetime']; public function journey(){return $this->belongsTo(Journey::class);} public function contact(){return $this->belongsTo(Contact::class);} public function tokens(){return $this->hasMany(JourneyToken::class);} }
