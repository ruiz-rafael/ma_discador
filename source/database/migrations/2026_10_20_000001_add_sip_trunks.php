<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('voice_sip_trunks',function(Blueprint $t){
   $t->uuid('id')->primary();$t->unsignedBigInteger('workspace_id')->index();
   $t->string('provider',30);$t->string('label',100);$t->string('number',20)->unique();
   $t->text('configuration');$t->boolean('enabled')->default(true);
   $t->unsignedInteger('revision')->default(1);$t->string('applied_hash',64)->nullable();
   $t->string('registration_state',30)->default('pending');$t->timestamp('checked_at')->nullable();$t->timestamps();
  });
 }
 public function down():void {Schema::dropIfExists('voice_sip_trunks');}
};
