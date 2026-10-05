<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('voice_live_queues',function(Blueprint $t){$t->boolean('allow_landline')->default(true);$t->boolean('allow_mobile')->default(true);});}
 public function down():void {Schema::table('voice_live_queues',fn(Blueprint $t)=>$t->dropColumn(['allow_landline','allow_mobile']));}
};
