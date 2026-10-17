<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('ai_connections',function(Blueprint $t){$t->unsignedBigInteger('workspace_id')->primary();$t->text('credentials');$t->text('sip_secret')->nullable();$t->string('project_id',120)->nullable();$t->boolean('enabled')->default(false);$t->unsignedInteger('daily_requests')->default(100);$t->timestamps();});
  Schema::create('ai_agents',function(Blueprint $t){$t->uuid('id')->primary();$t->unsignedBigInteger('workspace_id')->index();$t->string('name',120);$t->text('draft');$t->unsignedInteger('revision')->default(1);$t->unsignedInteger('published_version')->nullable();$t->boolean('paused')->default(false);$t->timestamps();});
  Schema::create('ai_agent_versions',function(Blueprint $t){$t->id();$t->uuid('agent_id');$t->unsignedInteger('version');$t->text('config');$t->unsignedBigInteger('published_by');$t->timestamps();$t->unique(['agent_id','version']);});
  Schema::create('ai_sessions',function(Blueprint $t){$t->uuid('id')->primary();$t->unsignedBigInteger('workspace_id')->index();$t->uuid('agent_id');$t->unsignedBigInteger('user_id')->nullable();$t->string('channel',24);$t->string('mode',24);$t->text('config');$t->unsignedInteger('revision');$t->string('status',24)->default('open');$t->string('provider_call_id',160)->nullable()->unique();$t->uuid('sip_binding_id')->nullable();$t->unsignedInteger('turns')->default(0);$t->unsignedInteger('input_tokens')->default(0);$t->unsignedInteger('output_tokens')->default(0);$t->decimal('estimated_usd',12,6)->default(0);$t->text('summary')->nullable();$t->timestamp('expires_at');$t->timestamps();});
  Schema::create('ai_turns',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('session_id')->index();$t->uuid('request_id');$t->unsignedInteger('sequence')->default(0);$t->string('request_hash',64);$t->text('input');$t->text('output')->nullable();$t->string('status',24);$t->string('action',24)->nullable();$t->unsignedInteger('latency_ms')->nullable();$t->timestamps();$t->unique(['session_id','request_id']);});
  Schema::create('ai_sip_bindings',function(Blueprint $t){$t->uuid('id')->primary();$t->unsignedBigInteger('workspace_id');$t->uuid('agent_id');$t->unsignedBigInteger('queue_id');$t->string('route_hash',64);$t->string('transfer_uri',250)->nullable();$t->boolean('enabled')->default(false);$t->timestamps();$t->unique(['workspace_id','agent_id']);});
  Schema::create('ai_whatsapp_bindings',function(Blueprint $t){$t->id();$t->unsignedBigInteger('workspace_id');$t->unsignedBigInteger('sender_id')->unique();$t->uuid('agent_id');$t->boolean('enabled')->default(false);$t->timestamp('enabled_at')->nullable();$t->timestamps();});
  Schema::create('ai_whatsapp_jobs',function(Blueprint $t){$t->uuid('message_id')->primary();$t->unsignedBigInteger('workspace_id');$t->uuid('conversation_id');$t->uuid('agent_id');$t->uuid('session_id')->nullable();$t->string('status',24)->default('pending');$t->string('reason',250)->nullable();$t->timestamps();});
 }
 public function down():void {foreach(['ai_whatsapp_jobs','ai_whatsapp_bindings','ai_sip_bindings','ai_turns','ai_sessions','ai_agent_versions','ai_agents','ai_connections']as $table)Schema::dropIfExists($table);}
};
