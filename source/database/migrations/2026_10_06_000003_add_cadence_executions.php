<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {
  Schema::create('voice_cadence_runs',function(Blueprint $t){
   $t->uuid('id')->primary();$t->foreignId('workspace_id')->constrained('voice_workspaces');$t->foreignId('campaign_id')->constrained('voice_campaigns');$t->foreignId('contact_id')->constrained('voice_contacts');
   $t->unsignedInteger('number');$t->string('status',20)->default('active');$t->string('exit_reason',32)->nullable();$t->string('trigger',30);$t->string('event_key',200)->nullable();$t->json('policy');$t->timestamp('reply_baseline')->nullable();$t->timestamp('entered_at');$t->timestamp('ended_at')->nullable();$t->timestamp('waiting_since')->nullable();$t->timestamps();
   $t->unique(['campaign_id','contact_id','number']);$t->unique(['campaign_id','contact_id','event_key']);$t->index(['campaign_id','status']);
  });
  DB::statement("CREATE UNIQUE INDEX voice_one_active_run ON voice_cadence_runs(campaign_id,contact_id) WHERE status IN ('active','waiting')");
  Schema::create('voice_cadence_membership',function(Blueprint $t){$t->foreignId('campaign_id')->constrained('voice_campaigns');$t->foreignId('contact_id')->constrained('voice_contacts');$t->boolean('present');$t->unsignedInteger('generation')->default(1);$t->unsignedInteger('consumed_generation')->default(0);$t->timestamps();$t->primary(['campaign_id','contact_id']);});
  Schema::create('voice_cadence_events',function(Blueprint $t){$t->id();$t->foreignId('campaign_id')->constrained('voice_campaigns');$t->string('event_key',200);$t->foreignId('contact_id')->constrained('voice_contacts');$t->string('request_hash',64);$t->json('result');$t->timestamps();$t->unique(['campaign_id','event_key']);});
  foreach(['voice_outbound_calls','voice_live_reservations','wa_messages','voice_followups']as $name)Schema::table($name,fn(Blueprint $t)=>$t->uuid('run_id')->nullable()->index());
  Schema::table('voice_followups',function(Blueprint $t){$t->dropUnique(['campaign_id','contact_id']);$t->string('cycle_key',40)->default('legacy');$t->unique(['campaign_id','contact_id','cycle_key']);});
 }
 public function down():void {throw new RuntimeException('Preserve execution history. Restore application with additive schema instead of dropping runs.');}
};
