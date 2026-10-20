<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('portal_test_calls',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('user_id')->constrained('users');$t->foreignId('tenant_id')->constrained('portal_tenants');$t->string('method',32);$t->string('origin',20);$t->string('destination',20);$t->string('request_hash',64);$t->string('state',24)->default('creating');$t->text('result')->nullable();$t->timestamps();$t->index(['user_id','state']);});}
 public function down():void {Schema::dropIfExists('portal_test_calls');}
};
