<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('wa_inbox_routes',function(Blueprint $t){$t->foreignId('sender_id')->primary()->constrained('wa_senders');$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->foreignId('queue_id')->constrained('voice_live_queues');$t->boolean('enabled')->default(false);$t->unsignedInteger('max_open')->default(5);$t->timestamps();});
  Schema::create('wa_conversations',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->foreignId('sender_id')->constrained('wa_senders');$t->foreignId('contact_id')->nullable()->constrained('voice_contacts');$t->string('phone',20);$t->foreignId('queue_id')->nullable()->constrained('voice_live_queues');$t->foreignId('assigned_user_id')->nullable()->constrained('users');$t->string('status',16)->default('open');$t->timestamp('last_message_at')->nullable();$t->timestamp('last_inbound_at')->nullable();$t->timestamp('read_at')->nullable();$t->json('attribution')->nullable();$t->unsignedInteger('revision')->default(1);$t->timestamps();$t->unique(['workspace_id','sender_id','phone']);});
  Schema::table('wa_messages',function(Blueprint $t){$t->uuid('conversation_id')->nullable()->index();$t->foreign('conversation_id')->references('id')->on('wa_conversations');});
  Schema::create('social_leads',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->string('source',32);$t->string('external_id',150);$t->string('name',160)->nullable();$t->string('phone',20)->nullable();$t->string('email',200)->nullable();$t->foreignId('contact_id')->nullable()->constrained('voice_contacts');$t->string('status',32)->default('awaiting_review');$t->boolean('consent')->default(false);$t->text('consent_evidence')->nullable();$t->json('attribution');$t->timestamps();$t->unique(['workspace_id','source','external_id']);});
 }
 public function down():void {Schema::dropIfExists('social_leads');Schema::table('wa_messages',function(Blueprint $t){$t->dropForeign(['conversation_id']);$t->dropColumn('conversation_id');});Schema::dropIfExists('wa_conversations');Schema::dropIfExists('wa_inbox_routes');}
};
