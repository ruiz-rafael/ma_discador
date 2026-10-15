<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
 public function up():void {app(\App\Services\PlatformBranding::class)->apply(true);}
 public function down():void { /* Preserve names edited after the rebrand. */ }
};
