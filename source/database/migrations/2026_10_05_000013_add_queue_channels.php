<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up():void {
  Schema::table('voice_live_queues',function(Blueprint $t){$t->string('voice_number',24)->nullable();$t->unsignedBigInteger('whatsapp_sender_id')->nullable();$t->string('number_mode',16)->default('separate');$t->boolean('channels_configured')->default(false);});
  // Preserve existing channel choices only when every linked campaign agrees.
  foreach(DB::table('voice_live_queues')->get()as $q){
   $ids=collect([$q->campaign_id])->merge(DB::table('voice_queue_campaigns')->where('queue_id',$q->id)->pluck('campaign_id'))->filter()->unique();
   $channels=DB::table('voice_campaigns')->where('workspace_id',$q->workspace_id)->whereIn('id',$ids)->get()->map(function($c){$s=json_decode($c->settings,true);return ['voice_number'=>$s['business_number']??null,'whatsapp_sender_id'=>$s['whatsapp_sender_id']??null,'number_mode'=>$s['number_mode']??'single'];})->unique(fn($s)=>json_encode($s));
   if($channels->count()===1&&$channels->first()['voice_number'])DB::table('voice_live_queues')->where('id',$q->id)->update($channels->first()+['channels_configured'=>true]);
  }
 }
 public function down():void {Schema::table('voice_live_queues',fn(Blueprint $t)=>$t->dropColumn(['voice_number','whatsapp_sender_id','number_mode','channels_configured']));}
};
