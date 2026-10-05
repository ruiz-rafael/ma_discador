<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('ma_list_settings',function(Blueprint $t){$t->string('mode')->default('manual');$t->string('rule_match')->default('all');$t->json('rules')->nullable();});
  Schema::create('ma_segment_pins',function(Blueprint $t){$t->id();$t->unsignedBigInteger('workspace_id');$t->string('kind');$t->unsignedBigInteger('list_id');$t->unsignedBigInteger('contact_id');$t->timestamp('created_at')->nullable();$t->unique(['workspace_id','kind','list_id','contact_id'],'segment_pins_unique');});
 }
 public function down():void {Schema::dropIfExists('ma_segment_pins');Schema::table('ma_list_settings',fn(Blueprint $t)=>$t->dropColumn(['mode','rule_match','rules']));}
};
