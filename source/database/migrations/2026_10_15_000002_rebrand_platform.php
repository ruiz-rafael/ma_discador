<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
 public function up():void {app(\App\Services\PlatformBranding::class)->apply();}
 public function down():void { /* Brand labels are not reversed over subsequent user edits. */ }
};
