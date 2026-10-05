<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CrmRecord extends Model { protected $guarded=[]; protected $casts=['data'=>'array'];  }
