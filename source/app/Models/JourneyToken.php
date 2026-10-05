<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JourneyToken extends Model { protected $guarded=[]; protected $casts=['context'=>'array','resume_at'=>'datetime']; public function subscriber(){return $this->belongsTo(JourneySubscriber::class,"journey_subscriber_id");} }
