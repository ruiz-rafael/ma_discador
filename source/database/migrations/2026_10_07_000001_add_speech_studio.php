<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('wa_messages',fn(Blueprint $t)=>$t->uuid('speech_asset_id')->nullable());
  Schema::create('speech_templates',function(Blueprint $t){$t->uuid('id')->primary();$t->unsignedBigInteger('workspace_id')->index();$t->string('name',160);$t->text('body');$t->string('voice',30);$t->decimal('speed',3,2)->default(1);$t->unsignedInteger('revision')->default(1);$t->timestamps();});
  Schema::create('speech_assets',function(Blueprint $t){$t->uuid('id')->primary();$t->unsignedBigInteger('workspace_id')->index();$t->unsignedBigInteger('user_id');$t->uuid('template_id')->nullable();$t->string('name',160);$t->text('body');$t->string('voice',30);$t->decimal('speed',3,2);$t->string('fingerprint',64)->index();$t->string('status',24)->default('generating');$t->decimal('duration',8,2)->nullable();$t->decimal('generation_seconds',8,2)->nullable();$t->string('error',300)->nullable();$t->timestamp('expires_at')->index();$t->timestamps();});
 }
 public function down():void {Schema::table('wa_messages',fn(Blueprint $t)=>$t->dropColumn('speech_asset_id'));Schema::dropIfExists('speech_assets');Schema::dropIfExists('speech_templates');}
};
