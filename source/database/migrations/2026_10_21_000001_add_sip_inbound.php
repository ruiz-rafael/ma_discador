<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('voice_inbound_routes',fn(Blueprint $t)=>$t->string('provider',24)->default('programmable_voice'));
  Schema::table('voice_inbound_calls',function(Blueprint $t){$t->string('provider',24)->default('programmable_voice');$t->string('sip_channel',120)->nullable();});
  Schema::table('voice_inbound_devices',function(Blueprint $t){$t->string('sip_identity',100)->nullable();$t->boolean('sip_ready')->default(false);});
 }
 public function down():void {
  Schema::table('voice_inbound_routes',fn(Blueprint $t)=>$t->dropColumn('provider'));
  Schema::table('voice_inbound_calls',fn(Blueprint $t)=>$t->dropColumn(['provider','sip_channel']));
  Schema::table('voice_inbound_devices',fn(Blueprint $t)=>$t->dropColumn(['sip_identity','sip_ready']));
 }
};
