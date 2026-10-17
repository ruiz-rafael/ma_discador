<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('ai_local_connections',function(Blueprint $t){
   $t->unsignedBigInteger('workspace_id')->primary();$t->string('text_provider',16)->default('openai');
   $t->string('endpoint',250)->nullable();$t->text('credentials')->nullable();$t->string('model',80)->default('qwen3:8b');
   $t->boolean('enabled')->default(false);$t->unsignedInteger('daily_requests')->default(100);
   $t->unsignedInteger('context_tokens')->default(8192);$t->unsignedInteger('parallel_requests')->default(1);
   $t->unsignedInteger('timeout_seconds')->default(35);$t->unsignedInteger('revision')->default(1);
   $t->timestamp('verified_at')->nullable();$t->timestamps();
  });
  Schema::table('ai_sessions',function(Blueprint $t){$t->string('provider',16)->nullable();$t->string('model',80)->nullable();});
 }
 public function down():void {Schema::table('ai_sessions',fn(Blueprint $t)=>$t->dropColumn(['provider','model']));Schema::dropIfExists('ai_local_connections');}
};
