<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('voice_managed_numbers',function(Blueprint $t){$t->string('number',20)->primary();$t->uuid('request_id')->unique();$t->string('provider_sid',34)->unique();$t->boolean('active')->default(false);$t->timestamps();});}
 public function down():void {Schema::dropIfExists('voice_managed_numbers');}
};
