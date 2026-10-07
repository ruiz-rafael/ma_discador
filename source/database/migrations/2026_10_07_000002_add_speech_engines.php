<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {foreach(['speech_templates','speech_assets'] as $table)Schema::table($table,fn(Blueprint $t)=>$t->string('engine',32)->default('kokoro'));}
 public function down():void {foreach(['speech_templates','speech_assets'] as $table)Schema::table($table,fn(Blueprint $t)=>$t->dropColumn('engine'));}
};
