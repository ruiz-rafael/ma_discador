<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\{VoiceQueue,VoiceSupervision};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceSupervisionTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    private int $contact;
    private int $campaign;
    private int $queue;

    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026,10,14)->setTime(15,0));
        $this->admin=$this->user('supervisor'); $this->actingAs($this->admin);
        $this->contact=DB::table('voice_contacts')->insertGetId(['workspace_id'=>1,'name'=>'Contato QA','phone'=>'+5511999990001','original_phone'=>'+5511999990001','source'=>'QA','consent'=>true]);
        $this->campaign=DB::table('voice_campaigns')->insertGetId(['workspace_id'=>1,'name'=>'Cadência QA','status'=>'paused','settings'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        $this->queue=DB::table('voice_live_queues')->insertGetId(['workspace_id'=>1,'campaign_id'=>$this->campaign,'name'=>'Fila QA','direction'=>'mixed','agent_ids'=>json_encode([$this->admin->id]),'status'=>'running','inbound_enabled'=>true,'revision'=>2,'created_at'=>now(),'updated_at'=>now()]);
    }
    private function user(string $role='agent',int $w=1): User
    {
        $u=User::factory()->create();$u->forceFill(['voice_workspace_id'=>$w,'voice_role'=>$role,'voice_enabled'=>true])->save();return $u;
    }
    private function callRow(array $extra=[]): string
    {
        $id=(string)Str::uuid();
        DB::table('voice_outbound_calls')->insert($extra+['id'=>$id,'workspace_id'=>1,'user_id'=>$this->admin->id,'contact_id'=>$this->contact,'campaign_id'=>$this->campaign,'queue_id'=>$this->queue,'campaign_revision'=>1,'idempotency_key'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'grant_hash'=>hash('sha256',$id),'configuration_hash'=>str_repeat('c',64),'method'=>'programmable_voice','destination'=>'+5511999990001','caller_id'=>'+551130000001','status'=>'no_answer','amd_mode'=>'off','answer_classification'=>'not_requested','max_seconds'=>120,'ring_seconds'=>30,'consent_evidence'=>'Fixture','grant_expires_at'=>now(),'deadline_at'=>now()->addMinutes(3),'started_at'=>now()->subMinutes(2),'answered_at'=>null,'ended_at'=>now()->subMinute(),'capacity_released_at'=>now()->subMinute(),'created_at'=>now()->subMinutes(2),'updated_at'=>now()]);return $id;
    }
    private function presence(User $u,array $extra=[]): void
    {
        DB::table('voice_agent_presence')->insert($extra+['user_id'=>$u->id,'workspace_id'=>1,'status'=>'available','last_seen_at'=>now(),'available_since'=>now()->subMinutes(3),'state_changed_at'=>now()->subMinutes(3),'created_at'=>now(),'updated_at'=>now()]);
    }
    public function test_results_distinguish_network_answer_human_machine_and_reserved(): void
    {
        $this->callRow(); $this->callRow(['status'=>'busy']);
        $this->callRow(['status'=>'completed','answered_at'=>now()->subMinute()]);
        $this->callRow(['status'=>'completed','answered_at'=>now()->subMinute(),'amd_mode'=>'detect','answer_classification'=>'human']);
        $machine=$this->callRow(['status'=>'completed','answered_at'=>now()->subMinute(),'amd_mode'=>'detect','answer_classification'=>'machine']);
        $this->callRow(['status'=>'completed','answered_at'=>now()->subMinute(),'amd_mode'=>'detect','answer_classification'=>'unknown']);
        $this->callRow(['status'=>'pending','started_at'=>null,'ended_at'=>null,'capacity_released_at'=>null]);
        $d=$this->getJson('/api/voice/supervision')->assertOk()->json();
        $this->assertEquals(7,$d['summary']['records']);$this->assertEquals(6,$d['summary']['attempts']);$this->assertEquals(4,$d['summary']['connected']);
        $this->assertEquals(1,$d['summary']['humans']);$this->assertEquals(1,$d['summary']['machines']);$this->assertSame(1,$d['live_total']);
        $this->getJson('/api/voice/supervision/calls?result=machine')->assertOk()->assertJsonPath('calls.total',1)->assertJsonPath('calls.data.0.id',$machine)->assertJsonMissingPath('calls.data.0.grant_hash');
        $this->getJson('/api/voice/supervision/calls?attempted=1')->assertOk()->assertJsonPath('calls.total',6);
        Http::assertNothingSent();
    }
    public function test_period_timezone_filters_search_and_pagination(): void
    {
        for($i=0;$i<26;$i++)$this->callRow();
        $this->callRow(['started_at'=>'2026-10-14 02:59:59','created_at'=>'2026-10-14 02:59:59']);
        $this->callRow(['started_at'=>'2026-10-15 03:00:00','created_at'=>'2026-10-15 03:00:00']);
        $this->getJson('/api/voice/supervision/calls')->assertOk()->assertJsonPath('calls.total',26)->assertJsonCount(25,'calls.data');
        $this->getJson('/api/voice/supervision/calls?page=2')->assertOk()->assertJsonCount(1,'calls.data');
        $this->getJson('/api/voice/supervision/calls?q=%25')->assertOk()->assertJsonPath('calls.total',0);
        $this->getJson('/api/voice/supervision/calls?q=99990001&queue_id='.$this->queue)->assertOk()->assertJsonPath('calls.total',26);
        $this->getJson('/api/voice/supervision?from=2026-08-01&to=2026-10-14')->assertUnprocessable();
        $this->getJson('/api/voice/supervision?from=2026-10-15&to=2026-10-14')->assertUnprocessable();
    }
    public function test_access_and_workspace_isolation(): void
    {
        DB::table('voice_workspaces')->insert(['id'=>2,'name'=>'Other']);
        $other=$this->user('admin',2);$this->callRow(['workspace_id'=>2,'user_id'=>$other->id]);
        $this->getJson('/api/voice/supervision')->assertOk()->assertJsonPath('summary.records',0);
        $this->getJson('/api/voice/supervision/calls?user_id='.$other->id)->assertNotFound();
        $this->actingAs($other)->getJson('/api/voice/supervision')->assertForbidden();
        $agent=$this->user();$this->actingAs($agent)->getJson('/api/voice/supervision')->assertForbidden();
        $this->postJson('/api/voice/supervision/queues/'.$this->queue.'/pause',['channel'=>'outbound','revision'=>2])->assertForbidden();
        $this->actingAs($this->user('admin'))->getJson('/api/voice/supervision')->assertOk();
    }
    public function test_team_prioritizes_calls_over_presence_and_tracks_idle_pause_wrapup_and_stale(): void
    {
        $manual=$this->user();$pause=$this->user();$wrap=$this->user();$stale=$this->user();
        $ids=[$this->admin->id,$manual->id,$pause->id,$wrap->id,$stale->id];
        DB::table('voice_live_queues')->where('id',$this->queue)->update(['agent_ids'=>json_encode($ids)]);
        $this->presence($this->admin);$this->presence($manual,['status'=>'offline']);$this->presence($pause,['status'=>'paused','pause_reason'=>'Intervalo']);
        $this->presence($wrap,['available_after'=>now()->addSeconds(25)]);$this->presence($stale,['last_seen_at'=>now()->subSeconds(91)]);
        $this->callRow(['user_id'=>$manual->id,'campaign_id'=>null,'status'=>'answered','answered_at'=>now()->subSeconds(40),'ended_at'=>null,'capacity_released_at'=>null]);
        $d=$this->getJson('/api/voice/supervision?queue_id='.$this->queue)->assertOk()->json();
        $this->assertSame(['idle'=>1,'manual'=>1,'paused'=>1,'wrapup'=>1,'offline'=>1],$d['agent_counts']);
        $this->assertSame(1,$d['queues'][0]['available']);$this->assertSame(2,$d['queues'][0]['busy']);
        $this->assertCount(5,$d['agents']);
    }
    public function test_inbound_offer_occupies_agent_even_before_call_has_owner(): void
    {
        $this->presence($this->admin);
        $route=DB::table('voice_inbound_routes')->insertGetId(['workspace_id'=>1,'number'=>'+551130000001','queue_id'=>$this->queue]);$id=(string)Str::uuid();
        DB::table('voice_inbound_calls')->insert(['id'=>$id,'workspace_id'=>1,'route_id'=>$route,'queue_id'=>$this->queue,'account_sid'=>'AC'.str_repeat('1',32),'call_sid'=>'CA'.str_repeat('1',32),'from_number'=>'+5511999990001','to_number'=>'+551130000001','status'=>'waiting','created_at'=>now(),'updated_at'=>now()]);
        DB::table('voice_inbound_offers')->insert(['id'=>(string)Str::uuid(),'call_id'=>$id,'user_id'=>$this->admin->id,'identity'=>'qa','status'=>'offered','created_at'=>now(),'updated_at'=>now()]);
        $this->getJson('/api/voice/supervision')->assertOk()->assertJsonPath('agents.0.state','reserved')->assertJsonPath('live_total',1)->assertJsonPath('queues.0.available',0);
        $this->getJson('/api/voice/supervision/calls?direction=inbound')->assertOk()->assertJsonPath('calls.data.0.result','waiting');
        DB::table('voice_inbound_calls')->where('id',$id)->update(['status'=>'ringing','user_id'=>$this->admin->id]);
        $this->getJson('/api/voice/supervision')->assertOk()->assertJsonPath('agents.0.state','reserved');
        DB::table('voice_inbound_calls')->where('id',$id)->update(['status'=>'unavailable','capacity_released_at'=>now(),'ended_at'=>now()]);
        $this->getJson('/api/voice/supervision/calls?direction=inbound')->assertOk()->assertJsonPath('calls.data.0.result','unavailable');
        $this->getJson('/api/voice/supervision?campaign_id='.$this->campaign)->assertOk()->assertJsonPath('live_total',0);
    }
    public function test_polling_does_not_settle_expired_reservations_or_call_provider(): void
    {
        $id=(string)Str::uuid();DB::table('voice_live_reservations')->insert(['id'=>$id,'workspace_id'=>1,'queue_id'=>$this->queue,'contact_id'=>$this->contact,'user_id'=>$this->admin->id,'idempotency_key'=>(string)Str::uuid(),'status'=>'reserved','expires_at'=>now()->subMinute(),'created_at'=>now()->subMinutes(3),'updated_at'=>now()->subMinutes(3)]);
        $before=DB::table('voice_live_reservations')->find($id);
        $this->getJson('/api/voice/supervision')->assertOk();$this->getJson('/api/voice/supervision/calls?live=1')->assertOk();
        $this->assertEquals($before,DB::table('voice_live_reservations')->find($id));Http::assertNothingSent();
    }
    public function test_pause_is_revision_checked_and_does_not_interrupt_calls(): void
    {
        $id=$this->callRow(['status'=>'answered','ended_at'=>null,'capacity_released_at'=>null]);
        $url='/api/voice/supervision/queues/'.$this->queue.'/pause';
        $this->postJson($url,['channel'=>'outbound','revision'=>1])->assertConflict();
        $this->postJson($url,['channel'=>'outbound','revision'=>2])->assertOk();
        $this->assertDatabaseHas('voice_live_queues',['id'=>$this->queue,'status'=>'paused','inbound_enabled'=>true,'revision'=>3]);
        $this->assertDatabaseHas('voice_outbound_calls',['id'=>$id,'status'=>'answered','capacity_released_at'=>null]);
        $this->postJson($url,['channel'=>'inbound','revision'=>3])->assertOk();
        $this->assertDatabaseHas('voice_live_queues',['id'=>$this->queue,'inbound_enabled'=>false,'revision'=>4]);
        $this->postJson($url,['channel'=>'activate','revision'=>4])->assertUnprocessable();Http::assertNothingSent();
    }
    public function test_presence_heartbeats_do_not_reset_state_clock(): void
    {
        $service=app(VoiceQueue::class);$service->presence(1,$this->admin->id,'paused','Intervalo');
        $original=DB::table('voice_agent_presence')->where('user_id',$this->admin->id)->value('state_changed_at');
        $this->travel(20)->seconds();$service->presence(1,$this->admin->id);
        $this->assertSame($original,DB::table('voice_agent_presence')->where('user_id',$this->admin->id)->value('state_changed_at'));
        $service->presence(1,$this->admin->id,'available');
        $this->assertNotSame($original,DB::table('voice_agent_presence')->where('user_id',$this->admin->id)->value('state_changed_at'));
    }
}
