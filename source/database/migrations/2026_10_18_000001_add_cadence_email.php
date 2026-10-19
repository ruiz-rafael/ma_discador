<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('voice_email_settings',function(Blueprint $t){$t->foreignId('campaign_id')->primary()->constrained('voice_campaigns');$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->boolean('enabled')->default(false);$t->unsignedInteger('revision')->default(1);$t->unsignedInteger('delay_minutes')->default(0);$t->string('subject',200);$t->text('body');$t->timestamps();});
  Schema::create('voice_email_followups',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->foreignId('campaign_id')->constrained('voice_campaigns');$t->foreignId('contact_id')->constrained('voice_contacts');$t->uuid('run_id')->nullable();$t->string('cycle_key',40);$t->uuid('call_id');$t->unsignedInteger('settings_revision');$t->unsignedInteger('campaign_revision');$t->string('destination',254)->nullable();$t->string('status',24)->default('pending');$t->string('reason',500)->nullable();$t->timestamp('due_at');$t->timestamp('submitted_at')->nullable();$t->timestamp('accepted_at')->nullable();$t->timestamps();$t->unique(['campaign_id','contact_id','cycle_key'],'email_cycle_unique');$t->index(['status','due_at']);});
  Schema::create('voice_email_optouts',function(Blueprint $t){$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->string('address_hash',64);$t->timestamp('created_at');$t->primary(['workspace_id','address_hash']);});
 }
 public function down():void {Schema::dropIfExists('voice_email_optouts');Schema::dropIfExists('voice_email_followups');Schema::dropIfExists('voice_email_settings');}
};
