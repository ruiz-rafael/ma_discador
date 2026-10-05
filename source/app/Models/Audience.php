<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Audience extends Model { protected $guarded=[]; protected $casts=[]; public function contacts(){return $this->belongsToMany(Contact::class);} }
