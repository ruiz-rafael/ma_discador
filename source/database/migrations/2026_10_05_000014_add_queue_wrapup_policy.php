<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('voice_live_queues',function(Blueprint $t){$t->boolean('wrapup_enabled')->default(true);$t->boolean('wrapup_allow_early')->default(false);});
  Schema::table('voice_agent_presence',function(Blueprint $t){$t->unsignedBigInteger('wrapup_queue_id')->nullable();$t->string('wrapup_source',64)->nullable();$t->uuid('wrapup_token')->nullable();$t->boolean('wrapup_allow_early')->default(false);});
 }
 public function down():void {Schema::table('voice_agent_presence',fn(Blueprint $t)=>$t->dropColumn(['wrapup_queue_id','wrapup_source','wrapup_token','wrapup_allow_early']));Schema::table('voice_live_queues',fn(Blueprint $t)=>$t->dropColumn(['wrapup_enabled','wrapup_allow_early']));}
};
