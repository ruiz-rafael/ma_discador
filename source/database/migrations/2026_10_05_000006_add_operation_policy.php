<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::create('ma_operation_policy',function(Blueprint $t){$t->foreignId('workspace_id')->primary()->constrained('voice_workspaces');$t->boolean('paused')->default(false);$t->unsignedInteger('voice_daily_limit')->default(50);$t->unsignedInteger('whatsapp_daily_limit')->default(100);$t->decimal('cost_alert_usd',12,2)->nullable();$t->unsignedInteger('revision')->default(0);$t->timestamps();});}
 public function down():void{Schema::dropIfExists('ma_operation_policy');}
};
