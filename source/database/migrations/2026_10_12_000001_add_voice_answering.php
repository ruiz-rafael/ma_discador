<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('voice_outbound_calls',function(Blueprint $t){
   $t->string('amd_mode',16)->default('off');$t->string('answer_classification',24)->default('not_requested');$t->string('amd_answered_by',32)->nullable();$t->unsignedInteger('amd_duration_ms')->nullable();$t->timestamp('amd_completed_at')->nullable();$t->timestamp('amd_reviewed_at')->nullable();$t->boolean('amd_count_machine')->default(false);$t->uuid('voicemail_asset_id')->nullable();$t->string('voicemail_status',24)->default('none');$t->timestamp('voicemail_requested_at')->nullable();$t->timestamp('voicemail_started_at')->nullable();$t->timestamp('voicemail_played_at')->nullable();$t->string('voicemail_reason',240)->nullable();
  });
  Schema::create('voice_answer_events',function(Blueprint $t){$t->id();$t->uuid('call_id');$t->string('child_sid',34);$t->string('answered_by',32);$t->unsignedInteger('duration_ms')->nullable();$t->timestamp('processed_at')->nullable();$t->timestamps();$t->unique(['call_id','child_sid','answered_by'],'voice_answer_event_unique');});
 }
 public function down():void {Schema::dropIfExists('voice_answer_events');Schema::table('voice_outbound_calls',fn(Blueprint $t)=>$t->dropColumn(['amd_mode','answer_classification','amd_answered_by','amd_duration_ms','amd_completed_at','amd_reviewed_at','amd_count_machine','voicemail_asset_id','voicemail_status','voicemail_requested_at','voicemail_started_at','voicemail_played_at','voicemail_reason']));}
};
