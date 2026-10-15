<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('portal_number_requests',function(Blueprint $t){
   $t->uuid('id')->primary();$t->foreignId('tenant_id')->constrained('portal_tenants');$t->foreignId('requested_by')->constrained('users');
   $t->string('ddd',2);$t->string('purpose',24);$t->string('notes',500)->nullable();$t->string('status',24)->default('requested');$t->string('message',500)->nullable();
   $t->json('quote')->nullable();$t->timestamp('quote_expires_at')->nullable();$t->string('number',20)->nullable()->unique();$t->string('provider_sid',34)->nullable()->unique();$t->string('account_sid',34)->nullable();$t->string('bundle_sid',34)->nullable();$t->string('address_sid',34)->nullable();
   $t->foreignId('handled_by')->nullable()->constrained('users');$t->timestamp('purchased_at')->nullable();$t->timestamp('released_at')->nullable();$t->timestamps();$t->index(['tenant_id','status']);
  });
  Schema::create('portal_number_connections',function(Blueprint $t){$t->foreignId('tenant_id')->primary()->constrained('portal_tenants');$t->string('application_sid',34)->nullable();$t->timestamps();});
 }
 public function down():void {Schema::dropIfExists('portal_number_connections');Schema::dropIfExists('portal_number_requests');}
};
