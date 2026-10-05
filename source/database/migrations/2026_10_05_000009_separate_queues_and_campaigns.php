<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up():void {
  Schema::table('voice_live_queues',function(Blueprint $t){$t->unsignedBigInteger('campaign_id')->nullable()->change();$t->string('direction',16)->default('mixed');$t->boolean('inbound_enabled')->default(true);$t->string('calling_method',24)->default('programmable_voice');});
  Schema::create('voice_queue_campaigns',function(Blueprint $t){$t->foreignId('queue_id')->constrained('voice_live_queues');$t->foreignId('campaign_id')->unique()->constrained('voice_campaigns');$t->primary(['queue_id','campaign_id']);});
  Schema::table('voice_live_reservations',fn(Blueprint $t)=>$t->foreignId('campaign_id')->nullable()->constrained('voice_campaigns'));
  foreach(DB::table('voice_live_queues')->get() as $q){if($q->campaign_id){DB::table('voice_queue_campaigns')->insert(['queue_id'=>$q->id,'campaign_id'=>$q->campaign_id]);DB::table('voice_live_reservations')->where('queue_id',$q->id)->update(['campaign_id'=>$q->campaign_id]);}$incoming=DB::table('voice_inbound_routes')->where('queue_id',$q->id)->exists();DB::table('voice_live_queues')->where('id',$q->id)->update(['direction'=>$incoming?'mixed':'outbound','inbound_enabled'=>$incoming]);}
 }
 public function down():void {throw new RuntimeException('Migração preserva vínculos e histórico. Reverta o código de forma compatível; não descarte vínculos de campanhas.');}
};
