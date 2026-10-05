<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('ma_channel_costs',function(Blueprint $t){$t->id();$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->string('channel',24);$t->uuid('reference_id');$t->string('provider_sid',34);$t->string('leg',24);$t->decimal('amount',16,6)->nullable();$t->string('currency',3)->nullable();$t->timestamp('synced_at');$t->unique(['workspace_id','channel','provider_sid']);$t->index(['workspace_id','channel','reference_id']);});
  Schema::create('ma_retention_policies',function(Blueprint $t){$t->foreignId('workspace_id')->primary()->constrained('voice_workspaces');$t->unsignedInteger('audio_days')->default(90);$t->unsignedInteger('session_days')->default(30);$t->unsignedInteger('delivery_days')->default(90);$t->boolean('automatic')->default(false);$t->unsignedInteger('revision')->default(0);$t->timestamps();});
  Schema::create('ma_retention_runs',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->foreignId('user_id')->nullable()->constrained('users');$t->uuid('request_key');$t->unsignedInteger('policy_revision');$t->json('counts');$t->timestamp('created_at');$t->timestamp('restored_at')->nullable();$t->unique(['workspace_id','request_key']);});
  foreach(['voice_audio_sessions','ma_embed_sessions','ma_event_deliveries']as $table)Schema::table($table,function(Blueprint $t){$t->timestamp('archived_at')->nullable()->index();$t->uuid('archive_batch_id')->nullable()->index();});
  Schema::table('ma_event_deliveries',function(Blueprint $t){$t->uuid('lease_token')->nullable();$t->string('last_error',40)->nullable();$t->unsignedInteger('revision')->default(1);});
  Schema::create('ma_delivery_attempts',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('delivery_id');$t->foreign('delivery_id')->references('id')->on('ma_event_deliveries');$t->unsignedInteger('attempt');$t->string('status',24);$t->unsignedInteger('http_status')->nullable();$t->string('error',40)->nullable();$t->timestamp('started_at');$t->timestamp('finished_at')->nullable();$t->index(['delivery_id','started_at']);});
 }
 public function down():void {Schema::dropIfExists('ma_delivery_attempts');Schema::table('ma_event_deliveries',fn(Blueprint $t)=>$t->dropColumn(['lease_token','last_error','revision']));foreach(['voice_audio_sessions','ma_embed_sessions','ma_event_deliveries']as $table)Schema::table($table,fn(Blueprint $t)=>$t->dropColumn(['archived_at','archive_batch_id']));foreach(['ma_retention_runs','ma_retention_policies','ma_channel_costs']as $t)Schema::dropIfExists($t);}
};
