<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Journey extends Model { protected $guarded=[]; protected $casts=['graph'=>'array','start_date'=>'datetime','end_date'=>'datetime','is_indefinite'=>'boolean']; public function audiences(){return $this->belongsToMany(Audience::class);} public function nodes(){return $this->hasMany(JourneyNode::class);} public function edges(){return $this->hasMany(JourneyEdge::class);} public function subscribers(){return $this->hasMany(JourneySubscriber::class);} }
