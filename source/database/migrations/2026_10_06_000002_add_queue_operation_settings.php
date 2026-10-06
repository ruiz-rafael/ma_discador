<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('voice_live_queues',function(Blueprint $t){
   $t->boolean('schedule_enabled')->default(false);$t->string('schedule_timezone',80)->default('America/Sao_Paulo');$t->json('schedule_days')->nullable();$t->string('schedule_start',5)->default('09:00');$t->string('schedule_end',5)->default('18:00');
   $t->unsignedInteger('agent_ring_seconds')->nullable();$t->unsignedInteger('missed_offer_limit')->default(0);$t->boolean('pause_on_disconnect')->default(false);
   $t->boolean('allow_international')->default(true);$t->boolean('allow_special')->default(false);
   $t->boolean('recording_enabled')->default(false);$t->boolean('recording_agent_access')->default(false);$t->boolean('recording_agent_pause')->default(false);$t->unsignedInteger('recording_retention_days')->default(30);
  });
  Schema::create('voice_agent_queue_counters',function(Blueprint $t){$t->foreignId('queue_id')->constrained('voice_live_queues')->cascadeOnDelete();$t->foreignId('user_id')->constrained('users')->cascadeOnDelete();$t->unsignedInteger('missed_offers')->default(0);$t->primary(['queue_id','user_id']);});
  foreach(['voice_inbound_calls','voice_outbound_calls']as $table)Schema::table($table,fn(Blueprint $t)=>$t->json('recording_policy')->nullable());
  Schema::create('voice_recordings',function(Blueprint $t){$t->string('id',34)->primary();$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->string('kind',12);$t->uuid('call_id');$t->foreignId('user_id')->nullable()->constrained('users');$t->string('account_sid',34);$t->string('parent_sid',34);$t->string('status',20);$t->unsignedInteger('duration')->nullable();$t->timestamp('expires_at');$t->timestamp('deleted_at')->nullable();$t->timestamp('control_at')->nullable();$t->timestamps();$t->index(['kind','call_id']);$t->index(['expires_at','deleted_at']);});
 }
 public function down():void {Schema::dropIfExists('voice_recordings');foreach(['voice_inbound_calls','voice_outbound_calls']as $table)Schema::table($table,fn(Blueprint $t)=>$t->dropColumn('recording_policy'));Schema::dropIfExists('voice_agent_queue_counters');Schema::table('voice_live_queues',fn(Blueprint $t)=>$t->dropColumn(['schedule_enabled','schedule_timezone','schedule_days','schedule_start','schedule_end','agent_ring_seconds','missed_offer_limit','pause_on_disconnect','allow_international','allow_special','recording_enabled','recording_agent_access','recording_agent_pause','recording_retention_days']));}
};
