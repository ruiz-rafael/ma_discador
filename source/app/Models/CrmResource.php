<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CrmResource extends Model {
 protected $guarded=[];
 protected $casts=['metadata'=>'array','active'=>'boolean'];
}
