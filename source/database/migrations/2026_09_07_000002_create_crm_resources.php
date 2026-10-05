<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('crm_resources',function(Blueprint $t){$t->id();$t->string('kind',40);$t->string('external_id',160);$t->string('name',200);$t->string('channel',30)->nullable();$t->string('origin',30)->default('preparation');$t->boolean('active')->default(true);$t->json('metadata')->nullable();$t->timestamps();$t->unique(['kind','external_id']);$t->index(['kind','active','channel']);});}
 public function down():void {Schema::dropIfExists('crm_resources');}
};
