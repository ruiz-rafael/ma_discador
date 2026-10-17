<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\{QueueDiagnostic,Segments};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueDiagnosticTest extends TestCase
{
 use RefreshDatabase;
 private User $admin;private int $campaign;private int $queue;private int $contact;
 protected function setUp():void{
  parent::setUp();Http::preventStrayRequests();$this->travelTo(now()->setDate(2026,10,17)->setTime(15,0));
  $this->admin=User::factory()->create();$this->admin->forceFill(['voice_workspace_id'=>1,'voice_role'=>'supervisor','voice_enabled'=>true])->save();$this->actingAs($this->admin);
  $settings=['timezone'=>'America/Sao_Paulo','days'=>[1,2,3,4,5,6,7],'start_time'=>'00:00','end_time'=>'23:59','max_attempts'=>5,'retry_minutes'=>10];
  $this->campaign=DB::table('voice_campaigns')->insertGetId(['workspace_id'=>1,'name'=>'Cadência de demonstração','status'=>'testing','settings'=>json_encode($settings)]);
  $this->queue=DB::table('voice_live_queues')->insertGetId(['workspace_id'=>1,'campaign_id'=>$this->campaign,'name'=>'Fila de demonstração','direction'=>'outbound','status'=>'running','mode'=>'progressive','voice_number'=>'+12025550123','agent_ids'=>json_encode([$this->admin->id])]);
  $this->contact=$this->contactRow();
 }
 private function contactRow(array $extra=[]):int{$i=DB::table('voice_contacts')->insertGetId($extra+['workspace_id'=>1,'name'=>'Pessoa de teste','phone'=>'+5511999990001','original_phone'=>'+5511999990001','source'=>'QA','consent'=>true,'consent_evidence'=>'Autorização de teste']);DB::table('voice_members')->insert(['campaign_id'=>$this->campaign,'contact_id'=>$i]);return $i;}
 private function presence(array $extra=[]):void{DB::table('voice_agent_presence')->insert($extra+['workspace_id'=>1,'user_id'=>$this->admin->id,'status'=>'available','last_seen_at'=>now()]);}
 private function endpoint():string{return '/api/voice/supervision/queues/'.$this->queue.'/diagnostic';}
 private function check(array $d,string $code):array{return collect($d['checks'])->firstWhere('code',$code);}
 public function test_reports_available_agent_and_contact_without_claiming_readiness_for_provider():void{
  $this->presence();$r=$this->getJson($this->endpoint())->assertOk()->assertHeader('Cache-Control','no-store, private');$d=$r->json();
  $this->assertSame('checks_passed',$d['status']);$this->assertSame(1,$d['audience']['eligible']);$this->assertSame(1,$d['agents']['counts']['available']);$this->assertStringContainsString('Não verifica operadora',$d['scope']);Http::assertNothingSent();
 }
 public function test_presence_must_be_recent_selected_and_not_in_wrapup():void{
  $this->presence(['last_seen_at'=>now()->subSeconds(91)]);$d=$this->getJson($this->endpoint())->assertOk()->json();$this->assertSame('blocked',$this->check($d,'agents')['status']);$this->assertSame(1,$d['agents']['counts']['offline']);
  DB::table('voice_agent_presence')->update(['last_seen_at'=>now(),'queue_ids'=>'[]']);$this->getJson($this->endpoint())->assertOk()->assertJsonPath('agents.counts.unselected',1);
  DB::table('voice_agent_presence')->update(['queue_ids'=>null,'available_after'=>now()->addSeconds(30)]);$this->getJson($this->endpoint())->assertOk()->assertJsonPath('agents.counts.wrapup',1);
  DB::table('voice_agent_presence')->update(['available_after'=>null,'status'=>'paused']);$this->getJson($this->endpoint())->assertOk()->assertJsonPath('agents.counts.paused',1);
 }
 public function test_permission_consent_and_retry_reasons_explain_first_impediment():void{
  DB::table('voice_live_queues')->where('id',$this->queue)->update(['allow_mobile'=>false]);$d=$this->getJson($this->endpoint())->assertOk()->json();$this->assertStringContainsString('celulares',$d['audience']['reasons'][0]['reason']);
  DB::table('voice_live_queues')->where('id',$this->queue)->update(['allow_mobile'=>true]);DB::table('voice_contacts')->where('id',$this->contact)->update(['consent'=>false]);$d=$this->getJson($this->endpoint())->assertOk()->json();$this->assertStringContainsString('autorização',$d['audience']['reasons'][0]['reason']);
  DB::table('voice_contacts')->where('id',$this->contact)->update(['consent'=>true]);
  DB::table('voice_outbound_calls')->insert(['id'=>(string)Str::uuid(),'workspace_id'=>1,'user_id'=>$this->admin->id,'contact_id'=>$this->contact,'campaign_id'=>$this->campaign,'queue_id'=>$this->queue,'campaign_revision'=>1,'idempotency_key'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'grant_hash'=>str_repeat('b',64),'configuration_hash'=>str_repeat('c',64),'method'=>'programmable_voice','destination'=>'+5511999990001','caller_id'=>'+12025550123','status'=>'no_answer','max_seconds'=>120,'ring_seconds'=>30,'consent_evidence'=>'QA','grant_expires_at'=>now(),'deadline_at'=>now(),'started_at'=>now()->subMinute(),'ended_at'=>now(),'capacity_released_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
  $d=$this->getJson($this->endpoint())->assertOk()->json();$this->assertStringContainsString('intervalo',$d['audience']['reasons'][0]['reason']);
  DB::table('voice_outbound_calls')->update(['capacity_released_at'=>null]);$d=$this->getJson($this->endpoint())->assertOk()->json();$this->assertSame('blocked',$this->check($d,'capacity')['status']);
 }
 public function test_inbound_requires_ready_recent_audio_and_route():void{
  $this->presence();DB::table('voice_live_queues')->where('id',$this->queue)->update(['direction'=>'inbound','inbound_enabled'=>true]);
  $d=$this->getJson($this->endpoint())->assertOk()->json();$this->assertSame('blocked',$this->check($d,'routes')['status']);$this->assertSame(0,$d['agents']['counts']['inbound_ready']);$this->assertSame(0,$d['audience']['evaluated']);
  DB::table('voice_inbound_routes')->insert(['workspace_id'=>1,'queue_id'=>$this->queue,'number'=>'+12025550123','enabled'=>true]);DB::table('voice_inbound_devices')->insert(['user_id'=>$this->admin->id,'workspace_id'=>1,'session_id'=>(string)Str::uuid(),'identity'=>'qa-private-identity','ready'=>true,'last_seen_at'=>now()]);
  $this->getJson($this->endpoint())->assertOk()->assertJsonPath('status','checks_passed')->assertJsonPath('agents.counts.inbound_ready',1)->assertJsonMissingPath('agents.data.0.identity');
  DB::table('voice_inbound_devices')->update(['last_seen_at'=>now()->subSeconds(46)]);$this->getJson($this->endpoint())->assertOk()->assertJsonPath('agents.counts.inbound_ready',0);
 }
 public function test_bounded_sample_does_not_claim_empty_queue_or_readiness():void{
  DB::table('voice_contacts')->where('id',$this->contact)->update(['consent'=>false]);for($i=2;$i<=101;$i++)$this->contactRow(['phone'=>sprintf('+55119999%05d',$i),'consent'=>$i===101]);
  $d=$this->getJson($this->endpoint())->assertOk()->json();$this->assertSame(101,$d['audience']['total']);$this->assertSame(100,$d['audience']['evaluated']);$this->assertTrue($d['audience']['partial']);$this->assertSame('attention',$this->check($d,'audience')['status']);$this->assertCount(3,$d['audience']['reasons'][0]['examples']);
 }
 public function test_snapshot_does_not_refresh_segments_or_settle_expired_reservations():void{
  $this->mock(Segments::class)->shouldNotReceive('refresh');$list=DB::table('voice_lists')->insertGetId(['workspace_id'=>1,'name'=>'Segmento registrado','source'=>'QA']);DB::table('voice_campaign_policies')->insert(['campaign_id'=>$this->campaign,'list_id'=>$list,'retry_minutes'=>'{}']);DB::table('voice_list_members')->insert(['list_id'=>$list,'contact_id'=>$this->contact,'status'=>'active']);
  $rid=(string)Str::uuid();DB::table('voice_live_reservations')->insert(['id'=>$rid,'workspace_id'=>1,'queue_id'=>$this->queue,'contact_id'=>$this->contact,'user_id'=>$this->admin->id,'idempotency_key'=>(string)Str::uuid(),'status'=>'reserved','expires_at'=>now()->subMinute()]);
  $writes=[];DB::listen(function($q)use(&$writes){if(preg_match('/^\s*(insert|update|delete|replace)\b/i',$q->sql))$writes[]=$q->sql;});
  $d=$this->getJson($this->endpoint())->assertOk()->json();$this->assertSame([],$writes);$this->assertStringContainsString('reservado',$d['audience']['reasons'][0]['reason']);$this->assertDatabaseHas('voice_live_reservations',['id'=>$rid,'status'=>'reserved']);Http::assertNothingSent();
 }
 public function test_automation_audience_diagnostic_is_read_only_too():void{
  $this->mock(Segments::class)->shouldNotReceive('refresh');$a=DB::table('audiences')->insertGetId(['name'=>'Público de teste']);DB::table('voice_campaign_policies')->insert(['campaign_id'=>$this->campaign,'audience_id'=>$a,'retry_minutes'=>'{}']);$c=DB::table('contacts')->insertGetId(['name'=>'Pessoa','phone'=>'+5511999990001','subscribed'=>true,'fields'=>json_encode(['consent_evidence'=>'QA autorizado'])]);DB::table('audience_contact')->insert(['audience_id'=>$a,'contact_id'=>$c]);
  $this->getJson($this->endpoint())->assertOk()->assertJsonPath('audience.eligible',1);Http::assertNothingSent();
 }
 public function test_access_isolated_and_pauses_are_preserved():void{
  DB::table('voice_live_queues')->where('id',$this->queue)->update(['status'=>'paused']);$d=$this->getJson($this->endpoint())->assertOk()->json();$this->assertSame('blocked',$this->check($d,'outbound')['status']);$this->assertDatabaseHas('voice_live_queues',['id'=>$this->queue,'status'=>'paused']);
  $this->admin->forceFill(['voice_role'=>'agent'])->save();$this->getJson($this->endpoint())->assertForbidden();$this->admin->forceFill(['voice_role'=>'admin'])->save();
  DB::table('voice_workspaces')->insert(['id'=>2,'name'=>'Outro workspace']);$q=DB::table('voice_live_queues')->insertGetId(['workspace_id'=>2,'name'=>'Fila alheia','agent_ids'=>'[]']);$this->getJson('/api/voice/supervision/queues/'.$q.'/diagnostic')->assertNotFound();
 }
}
