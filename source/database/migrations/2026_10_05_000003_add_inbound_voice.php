<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('voice_inbound_routes',function(Blueprint $t){$t->id();$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->string('number',20)->unique();$t->foreignId('queue_id')->constrained('voice_live_queues');$t->boolean('enabled')->default(false);$t->unsignedInteger('ring_seconds')->default(20);$t->unsignedInteger('wait_seconds')->default(120);$t->unsignedInteger('revision')->default(1);$t->string('provider_number_sid',34)->nullable();$t->timestamp('provider_synced_at')->nullable();$t->timestamps();});
  Schema::create('voice_inbound_devices',function(Blueprint $t){$t->foreignId('user_id')->primary()->constrained('users');$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->uuid('session_id');$t->string('identity',100);$t->boolean('ready')->default(false);$t->timestamp('last_seen_at');});
  Schema::create('voice_inbound_calls',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->foreignId('route_id')->constrained('voice_inbound_routes');$t->foreignId('queue_id')->constrained('voice_live_queues');$t->string('account_sid',34);$t->string('call_sid',34)->unique();$t->string('from_number',80);$t->string('to_number',20);$t->string('status',24)->default('waiting');$t->foreignId('user_id')->nullable()->constrained('users');$t->timestamp('answered_at')->nullable();$t->timestamp('ended_at')->nullable();$t->unsignedInteger('bill_seconds')->nullable();$t->timestamp('capacity_released_at')->nullable();$t->string('disposition_code',40)->nullable();$t->text('notes')->nullable();$t->unsignedInteger('revision')->default(1);$t->timestamps();$t->index(['workspace_id','status']);});
  Schema::create('voice_inbound_offers',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('call_id');$t->foreign('call_id')->references('id')->on('voice_inbound_calls');$t->foreignId('user_id')->constrained('users');$t->string('identity',100);$t->string('status',24)->default('offered');$t->string('child_sid',34)->nullable()->unique();$t->boolean('rendered')->default(false);$t->timestamp('answered_at')->nullable();$t->timestamp('ended_at')->nullable();$t->timestamps();$t->index(['user_id','status']);});
 }
 public function down():void {foreach(['voice_inbound_offers','voice_inbound_calls','voice_inbound_devices','voice_inbound_routes'] as $t)Schema::dropIfExists($t);}
};
