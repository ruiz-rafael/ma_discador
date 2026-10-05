<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Contact extends Model { protected $guarded=[]; protected $casts=['fields'=>'array','tags'=>'array','subscribed'=>'boolean']; public function audiences(){return $this->belongsToMany(Audience::class);} }
