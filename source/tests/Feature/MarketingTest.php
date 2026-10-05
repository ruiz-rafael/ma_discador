<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Models\{User,Audience,Contact,Journey,JourneyVersion,JourneyToken,JourneySubscriber,JourneyNodeLog};
use App\Services\{GraphValidator,JourneyExecutionEngine};
class MarketingTest extends TestCase {
 use RefreshDatabase;
 private function graph(string $action='add_tag',array $settings=['tag'=>'qualificado']):array{return ['nodes'=>[['id'=>'start','position'=>['x'=>0,'y'=>0],'data'=>['key'=>'open_trigger','settings'=>[]]],['id'=>'action','position'=>['x'=>0,'y'=>100],'data'=>['key'=>$action,'settings'=>$settings]]],'edges'=>[['id'=>'edge','source'=>'start','target'=>'action','sourceHandle'=>'success']]];}
 private function setupJourney(?array $graph=null):array{Queue::fake();$c=Contact::create(['name'=>'Teste','subscribed'=>true]);$j=Journey::create(['title'=>'Teste','status'=>'active','published_version'=>1,'graph'=>$graph??$this->graph(),'is_indefinite'=>true]);JourneyVersion::create(['journey_id'=>$j->id,'version'=>1,'graph'=>$j->graph]);return [$j,$c,app(JourneyExecutionEngine::class)];}
 public function test_api_requires_authentication():void{$this->getJson('/api/bootstrap')->assertUnauthorized();}
 public function test_authenticated_user_can_create_save_publish_and_conflicts_are_detected():void{
  $this->actingAs(User::factory()->create());$a=Audience::create(['name'=>'Lista']);
  $id=$this->postJson('/api/journeys',['title'=>'Boas-vindas','audience_ids'=>[$a->id],'start_date'=>now()->toIso8601String(),'is_indefinite'=>true])->assertSuccessful()->json('id');
  $this->putJson("/api/journeys/$id/graph",['revision'=>0,'graph'=>$this->graph()])->assertOk()->assertJsonPath('revision',1);
  $this->putJson("/api/journeys/$id/graph",['revision'=>0,'graph'=>$this->graph()])->assertConflict();
  $this->postJson("/api/journeys/$id/publish")->assertOk()->assertJsonPath('status','active');$this->assertDatabaseCount('journey_versions',1);
 }
 public function test_graph_rejects_unknown_handles_and_cycles():void{
  $g=$this->graph();$g['edges'][0]['sourceHandle']='invalid';try{app(GraphValidator::class)->validate($g,true);$this->fail('Invalid handle accepted');}catch(\Illuminate\Validation\ValidationException $e){$this->assertStringContainsString('Porta',$e->getMessage());}
  $g=$this->graph();$g['edges'][]=['source'=>'action','target'=>'action','sourceHandle'=>'success'];$this->expectException(\Illuminate\Validation\ValidationException::class);app(GraphValidator::class)->validate($g,true);
 }
 public function test_execution_is_idempotent_and_uses_published_version():void{
  [$j,$c,$engine]=$this->setupJourney();$sub=$engine->enroll($j,$c,'one');$engine->enroll($j,$c,'one');$this->assertDatabaseCount('journey_subscribers',1);
  $j->update(['graph'=>$this->graph('add_tag',['tag'=>'draft-only'])]);$start=$sub->tokens()->first();$engine->execute($start->id);$engine->execute($start->id);$next=$sub->tokens()->where('node_uuid','action')->first();$engine->execute($next->id);$engine->execute($next->id);
  $this->assertSame(['qualificado'],$c->fresh()->tags);$this->assertDatabaseCount('journey_node_logs',2);$this->assertSame('completed',$sub->fresh()->status);
 }
 public function test_pause_prevents_execution_and_delay_resumes():void{
  [$j,$c,$e]=$this->setupJourney($this->graph('delay',['minutes'=>5]));$sub=$e->enroll($j,$c,'delay');$token=$sub->tokens()->first();$j->update(['status'=>'paused']);$e->execute($token->id);$this->assertSame('pending',$token->fresh()->status);$j->update(['status'=>'active']);$e->execute($token->id);$delay=$sub->tokens()->where('node_uuid','action')->first();$e->execute($delay->id);$this->assertSame('waiting',$delay->fresh()->status);$e->execute($delay->id);$this->assertSame('waiting',$delay->fresh()->status);$this->travel(6)->minutes();$e->execute($delay->id);$this->assertSame('completed',$sub->fresh()->status);
 }
 public function test_opt_out_blocks_send_without_success_log():void{
  [$j,$c,$e]=$this->setupJourney($this->graph('send_email',['template'=>'welcome','subject'=>'Hello']));$c->update(['subscribed'=>false]);$s=$e->enroll($j,$c,'send');$e->execute($s->tokens()->first()->id);$e->execute($s->tokens()->where('node_uuid','action')->first()->id);$this->assertSame('failed',$s->fresh()->status);$this->assertDatabaseHas('journey_node_logs',['node_uuid'=>'action','status'=>'failed']);
 }
 public function test_webhook_requires_signature_and_deduplicates():void{
  [$j,$c,$e]=$this->setupJourney();$a=Audience::create(['name'=>'API']);$j->audiences()->attach($a);$c->audiences()->attach($a);config(['marketing.webhook_secret'=>'secret']);$data=['event_key'=>'evt-1','contact_id'=>$c->id,'type'=>'open_trigger'];$this->postJson('/events',$data)->assertUnauthorized();$stamp=(string)time();$body=json_encode($data);$headers=['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_MA_TIMESTAMP'=>$stamp,'HTTP_X_MA_SIGNATURE'=>hash_hmac('sha256',$stamp.'.'.$body,'secret')];$this->call('POST','/events',[],[],[],$headers,$body)->assertOk();$this->call('POST','/events',[],[],[],$headers,$body)->assertOk()->assertJsonPath('duplicate',true);$this->assertDatabaseCount('journey_subscribers',1);
 }
 public function test_if_else_routes_only_matching_branch():void{
  $g=$this->graph('if_else',['field'=>'score','operator'=>'gt','value'=>10]);$g['nodes'][]=['id'=>'yes','position'=>['x'=>0,'y'=>200],'data'=>['key'=>'add_tag','settings'=>['tag'=>'yes']]];$g['nodes'][]=['id'=>'no','position'=>['x'=>100,'y'=>200],'data'=>['key'=>'add_tag','settings'=>['tag'=>'no']]];$g['edges'][]=['source'=>'action','target'=>'yes','sourceHandle'=>'yes'];$g['edges'][]=['source'=>'action','target'=>'no','sourceHandle'=>'no'];[$j,$c,$e]=$this->setupJourney($g);$s=$e->enroll($j,$c,'if');$e->execute($s->tokens()->first()->id);$e->execute($s->tokens()->where('node_uuid','action')->first()->id);$this->assertFalse($s->tokens()->where('node_uuid','yes')->exists());$e->execute($s->tokens()->where('node_uuid','no')->first()->id);$this->assertSame(['no'],$c->fresh()->tags);
 }
 public function test_future_journey_does_not_accept_contacts():void{[$j,$c,$e]=$this->setupJourney();$j->update(['start_date'=>now()->addDay()]);$this->assertNull($e->enroll($j,$c,'future'));}
}
