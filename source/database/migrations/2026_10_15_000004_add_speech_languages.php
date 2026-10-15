<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('speech_templates',function(Blueprint $t){$t->string('language',10)->default('pt-BR');$t->text('pronunciations')->nullable();});
  Schema::table('speech_assets',function(Blueprint $t){$t->string('language',10)->default('pt-BR');$t->text('spoken_body')->nullable();});
 }
 public function down():void {
  Schema::table('speech_templates',fn(Blueprint $t)=>$t->dropColumn(['language','pronunciations']));
  Schema::table('speech_assets',fn(Blueprint $t)=>$t->dropColumn(['language','spoken_body']));
 }
};
