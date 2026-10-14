<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('portal_operators',function(Blueprint $t){$t->foreignId('user_id')->primary()->constrained('users');});
  Schema::create('portal_tenants',function(Blueprint $t){$t->id();$t->string('slug',48)->unique();$t->string('name',160);$t->string('status',24)->default('provisioning');$t->string('url',250)->nullable();$t->text('secret')->nullable();$t->string('plan',80)->default('Inicial');$t->unsignedInteger('seats')->default(3);$t->unsignedInteger('channels')->default(1);$t->unsignedInteger('storage_mb')->default(1024);$t->json('usage')->nullable();$t->timestamp('checked_at')->nullable();$t->string('provision_error',240)->nullable();$t->timestamps();});
  Schema::create('portal_memberships',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained('portal_tenants');$t->foreignId('user_id')->constrained('users');$t->string('role',24);$t->boolean('active')->default(true);$t->timestamps();$t->unique(['tenant_id','user_id']);});
  Schema::create('portal_invitations',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained('portal_tenants');$t->string('email');$t->string('role',24);$t->string('token_hash',64)->unique();$t->timestamp('expires_at');$t->timestamp('accepted_at')->nullable();$t->timestamps();});
  Schema::create('portal_grants',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained('portal_tenants');$t->foreignId('user_id')->constrained('users');$t->string('token_hash',64)->unique();$t->string('state_hash',64);$t->timestamp('expires_at');$t->timestamp('used_at')->nullable();$t->timestamps();});
  Schema::create('portal_audit',function(Blueprint $t){$t->id();$t->foreignId('user_id')->nullable()->constrained('users');$t->foreignId('tenant_id')->nullable()->constrained('portal_tenants');$t->string('event',80);$t->json('details');$t->timestamp('created_at');});
 }
 public function down():void {foreach(['portal_audit','portal_grants','portal_invitations','portal_memberships','portal_tenants','portal_operators'] as $t)Schema::dropIfExists($t);}
};
