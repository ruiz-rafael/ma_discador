<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('voice_live_queues',function(Blueprint $t){$t->string('distribution_strategy',24)->default('channel_default');$t->unsignedBigInteger('distribution_cursor')->default(0);});
  Schema::table('voice_agent_presence',fn(Blueprint $t)=>$t->timestamp('available_since')->nullable());
  Schema::create('voice_queue_agent_turns',function(Blueprint $t){$t->foreignId('queue_id')->constrained('voice_live_queues')->cascadeOnDelete();$t->foreignId('user_id')->constrained('users')->cascadeOnDelete();$t->string('channel',16);$t->unsignedBigInteger('last_sequence')->default(0);$t->timestamp('assigned_at')->nullable();$t->timestamp('requested_at')->nullable();$t->primary(['queue_id','user_id','channel']);});
 }
 public function down():void {Schema::dropIfExists('voice_queue_agent_turns');Schema::table('voice_agent_presence',fn(Blueprint $t)=>$t->dropColumn('available_since'));Schema::table('voice_live_queues',fn(Blueprint $t)=>$t->dropColumn(['distribution_strategy','distribution_cursor']));}
};
