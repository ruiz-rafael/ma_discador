<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('ma_integrations',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->foreignId('created_by')->constrained('users');$t->string('name',160);$t->string('token_hash',64)->unique();$t->json('scopes');$t->json('list_keys');$t->json('agent_ids');$t->string('embed_origin',255)->nullable();$t->string('webhook_url',1000)->nullable();$t->text('webhook_secret');$t->boolean('active')->default(true);$t->timestamp('last_used_at')->nullable();$t->timestamps();});
  Schema::create('ma_integration_requests',function(Blueprint $t){$t->id();$t->uuid('integration_id');$t->foreign('integration_id')->references('id')->on('ma_integrations');$t->uuid('key');$t->string('request_hash',64);$t->json('response');$t->timestamps();$t->unique(['integration_id','key']);});
  Schema::create('ma_embed_sessions',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('integration_id');$t->foreign('integration_id')->references('id')->on('ma_integrations');$t->foreignId('user_id')->constrained('users');$t->unsignedInteger('auth_version')->default(0);$t->string('token_hash',64)->unique();$t->timestamp('expires_at');$t->timestamps();});
  Schema::create('ma_public_events',function(Blueprint $t){$t->bigIncrements('sequence');$t->uuid('id')->unique();$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->string('type',80);$t->string('dedup_key',180);$t->json('payload');$t->timestamp('created_at');$t->unique(['workspace_id','dedup_key']);});
  Schema::create('ma_event_deliveries',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('integration_id');$t->foreign('integration_id')->references('id')->on('ma_integrations');$t->unsignedBigInteger('event_sequence');$t->foreign('event_sequence')->references('sequence')->on('ma_public_events');$t->string('status',20)->default('pending');$t->unsignedInteger('attempts')->default(0);$t->unsignedInteger('http_status')->nullable();$t->timestamp('next_at');$t->timestamp('locked_until')->nullable();$t->timestamps();$t->unique(['integration_id','event_sequence']);});
 }
 public function down():void{foreach(['ma_event_deliveries','ma_public_events','ma_embed_sessions','ma_integration_requests','ma_integrations'] as $t)Schema::dropIfExists($t);}
};
