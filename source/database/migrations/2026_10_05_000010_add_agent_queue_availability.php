<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('voice_agent_presence',function(Blueprint $t){$t->json('queue_ids')->nullable();$t->uuid('session_id')->nullable();});
  Schema::table('voice_live_queues',fn(Blueprint $t)=>$t->boolean('manual_enabled')->default(true));
  Schema::table('voice_live_reservations',fn(Blueprint $t)=>$t->string('kind',16)->default('campaign'));
  Schema::table('voice_outbound_calls',fn(Blueprint $t)=>$t->boolean('manual')->default(false));
 }
 public function down():void {Schema::table('voice_outbound_calls',fn(Blueprint $t)=>$t->dropColumn('manual'));Schema::table('voice_live_reservations',fn(Blueprint $t)=>$t->dropColumn('kind'));Schema::table('voice_live_queues',fn(Blueprint $t)=>$t->dropColumn('manual_enabled'));Schema::table('voice_agent_presence',fn(Blueprint $t)=>$t->dropColumn(['queue_ids','session_id']));}
};
