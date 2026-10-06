<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\{InboundVoice,QueueDistribution,VoiceQueue,VoiceLiveQueue,VoiceCalling,VoiceCapacity,ConversationInbox};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;
class QueueDistributionTest extends TestCase {
 use RefreshDatabase;
 private array $ids=[];private int $queue;private int $route;private int $counter=0;
 protected function setUp():void {
  parent::setUp();Http::preventStrayRequests();config(['voice_capacity.simultaneous_calls'=>3]);
  for($i=0;$i<3;$i++){$u=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'agent']);$this->ids[]=$u->id;DB::table('voice_agent_presence')->insert(['user_id'=>$u->id,'workspace_id'=>1,'status'=>'available','last_seen_at'=>now(),'available_since'=>now()->subMinutes(3-$i)]);DB::table('voice_inbound_devices')->insert(['user_id'=>$u->id,'workspace_id'=>1,'session_id'=>(string)Str::uuid(),'identity'=>'qa_'.$u->id,'ready'=>true,'last_seen_at'=>now()]);}
  $this->queue=DB::table('voice_live_queues')->insertGetId(['workspace_id'=>1,'name'=>'QA distribuição','direction'=>'mixed','status'=>'running','inbound_enabled'=>true,'mode'=>'progressive','agent_ids'=>json_encode($this->ids),'distribution_strategy'=>'round_robin','wrapup_enabled'=>false]);
  $this->route=DB::table('voice_inbound_routes')->insertGetId(['workspace_id'=>1,'queue_id'=>$this->queue,'number'=>'+12025550123','enabled'=>true]);
 }
 private function receive():?object{$this->counter++;$sid='CA'.str_pad((string)$this->counter,32,'0',STR_PAD_LEFT);app(InboundVoice::class)->receive(['AccountSid'=>'AC'.str_repeat('a',32),'CallSid'=>$sid,'From'=>'+5511999990000','To'=>'+12025550123']);return DB::table('voice_inbound_calls')->where('call_sid',$sid)->first();}
 private function end(object $c):void{app(InboundVoice::class)->ended(['AccountSid'=>$c->account_sid,'CallSid'=>$c->call_sid,'CallStatus'=>'completed','CallDuration'=>0]);}
 private function q():object{return DB::table('voice_live_queues')->find($this->queue);}
 public function test_three_simultaneous_offers_use_three_agents_and_fourth_exceeds_capacity():void{
  $calls=[$this->receive(),$this->receive(),$this->receive()];$this->assertSame($this->ids,array_column($calls,'user_id'));$this->assertNull($this->receive());$this->assertDatabaseCount('voice_inbound_calls',3);$this->assertDatabaseCount('voice_inbound_offers',3);
  foreach($calls as $c)$this->assertStringContainsString('<Hangup',app(InboundVoice::class)->dispatch($c->id,$c->call_sid));$this->assertDatabaseCount('voice_inbound_offers',3);Http::assertNothingSent();
 }
 public function test_round_robin_keeps_turn_after_release_and_does_not_depend_on_heartbeat():void{
  $chosen=[];for($i=0;$i<9;$i++){$c=$this->receive();$chosen[]=$c->user_id;$this->end($c);DB::table('voice_agent_presence')->where('user_id',$this->ids[0])->update(['last_seen_at'=>now(),'updated_at'=>now()]);}
  $this->assertSame(array_merge($this->ids,$this->ids,$this->ids),$chosen);$this->assertSame(9,$this->q()->distribution_cursor);Http::assertNothingSent();
 }
 public function test_skips_paused_offline_stale_unselected_and_wrapup_agents():void{
  foreach([['status'=>'paused'],['status'=>'offline'],['last_seen_at'=>now()->subMinutes(2)],['queue_ids'=>'[]'],['available_after'=>now()->addMinute()]]as $blocked){
   DB::table('voice_agent_presence')->whereIn('user_id',array_slice($this->ids,0,2))->update($blocked);$c=$this->receive();$this->assertSame($this->ids[2],$c->user_id);$this->end($c);
   DB::table('voice_agent_presence')->update(['status'=>'available','last_seen_at'=>now(),'queue_ids'=>null,'available_after'=>null]);
  }
  DB::table('voice_agent_presence')->update(['status'=>'paused']);$c=$this->receive();$this->assertNull($c->user_id);$this->assertSame('waiting',$c->status);Http::assertNothingSent();
 }
 public function test_rejected_offer_goes_to_next_agent_and_repeated_callback_does_not_advance_turn():void{
  $c=$this->receive();$o=DB::table('voice_inbound_offers')->where('call_id',$c->id)->first();$payload=['AccountSid'=>$c->account_sid,'CallSid'=>$c->call_sid,'DialCallStatus'=>'no-answer'];app(InboundVoice::class)->finish($o->id,$payload);$this->assertDatabaseHas('voice_inbound_calls',['id'=>$c->id,'user_id'=>$this->ids[1]]);app(InboundVoice::class)->finish($o->id,$payload);$this->assertSame(2,$this->q()->distribution_cursor);$this->assertDatabaseCount('voice_inbound_offers',2);
 }
 public function test_longest_idle_uses_real_free_time_not_heartbeat_and_available_transition_resets_it():void{
  DB::table('voice_live_queues')->where('id',$this->queue)->update(['distribution_strategy'=>'longest_idle']);
  DB::table('voice_agent_presence')->where('user_id',$this->ids[0])->update(['available_after'=>now()->subSeconds(2)]);
  $c=$this->receive();$this->assertSame($this->ids[1],$c->user_id);$this->end($c);
  $old=DB::table('voice_agent_presence')->where('user_id',$this->ids[2])->value('available_since');app(VoiceQueue::class)->presence(1,$this->ids[2]);$this->assertSame($old,DB::table('voice_agent_presence')->where('user_id',$this->ids[2])->value('available_since'));
  app(VoiceQueue::class)->presence(1,$this->ids[2],'paused','QA');app(VoiceQueue::class)->presence(1,$this->ids[2],'available');$this->assertNotSame($old,DB::table('voice_agent_presence')->where('user_id',$this->ids[2])->value('available_since'));$this->assertSame($this->ids[0],$this->receive()->user_id);
 }
 public function test_queue_strategy_validation_role_and_revision():void{
  $admin=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin']);$d=['name'=>'QA','direction'=>'mixed','mode'=>'progressive','strategy'=>'fifo','wrapup_seconds'=>0,'agent_ids'=>$this->ids,'distribution_strategy'=>'round_robin','revision'=>0];
  DB::table('voice_live_queues')->where('id',$this->queue)->update(['status'=>'paused']);$url='/api/voice/operations/queues/'.$this->queue;
  $this->actingAs(User::find($this->ids[0]))->putJson($url,$d)->assertForbidden();$this->actingAs($admin)->putJson($url,array_replace($d,['distribution_strategy'=>'random_anyone']))->assertUnprocessable();$this->putJson($url,$d)->assertOk();$this->putJson($url,$d)->assertConflict();$this->getJson('/api/voice/operations/queues')->assertJsonPath('queues.0.distribution_strategy','round_robin');
 }
 public function test_outbound_turn_ignores_old_poll_and_busy_agent_without_advancing_on_denial():void{
  $q=$this->q();$distribution=app(QueueDistribution::class);
  DB::transaction(function()use($q,$distribution){DB::table('voice_runtime')->where('id',1)->lockForUpdate()->first();foreach($this->ids as $u){DB::table('voice_queue_agent_turns')->insert(['queue_id'=>$q->id,'user_id'=>$u,'channel'=>'outbound','requested_at'=>now()]);}
   $this->assertFalse($distribution->outboundTurn($q,$this->ids[1]));$this->assertTrue($distribution->outboundTurn($q,$this->ids[0]));$distribution->record($q,$this->ids[0],'outbound');$this->assertTrue($distribution->outboundTurn($q,$this->ids[1]));
   DB::table('voice_queue_agent_turns')->where('user_id',$this->ids[1])->update(['requested_at'=>now()->subSeconds(16)]);$this->assertTrue($distribution->outboundTurn($q,$this->ids[2]));$this->assertSame(1,$this->q()->distribution_cursor);
  });
 }
 public function test_whatsapp_uses_same_selected_rule_and_max_open_without_sending():void{
  $sender=DB::table('wa_senders')->insertGetId(['workspace_id'=>1,'provider'=>'qr','label'=>'QA','number'=>'+5511000000000','ownership'=>'external']);DB::table('wa_inbox_routes')->insert(['workspace_id'=>1,'sender_id'=>$sender,'queue_id'=>$this->queue,'enabled'=>true,'max_open'=>1]);
  $owners=[];for($i=0;$i<4;$i++){$id=(string)Str::uuid();DB::table('wa_conversations')->insert(['id'=>$id,'workspace_id'=>1,'sender_id'=>$sender,'queue_id'=>$this->queue,'phone'=>'+551199999000'.$i,'status'=>'open']);app(ConversationInbox::class)->distribute($id);$owners[]=DB::table('wa_conversations')->where('id',$id)->value('assigned_user_id');app(ConversationInbox::class)->distribute($id);}
  $this->assertSame([...$this->ids,null],$owners);$this->assertSame(3,$this->q()->distribution_cursor);$this->assertDatabaseCount('wa_messages',0);Http::assertNothingSent();
 }
 public function test_default_capacity_is_still_one_when_override_is_absent():void{config(['voice_capacity.simultaneous_calls'=>1]);$this->assertSame(1,app(VoiceCapacity::class)->limit());$this->assertNotNull($this->receive());$this->assertNull($this->receive());}
}
