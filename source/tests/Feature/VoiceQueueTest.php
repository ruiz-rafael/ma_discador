<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VoiceQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private User $other;
    private int $campaign;
    private array $contacts;
    private int $queue;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(15, 0));
        $this->agent = $this->user(); $this->other = $this->user();
        $this->actingAs($this->agent);
        $this->contacts=[];
        foreach ([1,2,3] as $n) $this->contacts[]=DB::table('voice_contacts')->insertGetId(['workspace_id'=>1,'name'=>'Queue QA '.$n,'phone'=>'+551199999000'.$n,'original_phone'=>'+551199999000'.$n,'source'=>'Fixture','consent'=>true,'consent_evidence'=>'Test authorization','created_at'=>now(),'updated_at'=>now()]);
        $this->campaign=DB::table('voice_campaigns')->insertGetId(['workspace_id'=>1,'name'=>'Queue campaign','status'=>'testing','settings'=>json_encode(['mode'=>'preview','timezone'=>'UTC','days'=>[1,2,3,4,5,6,7],'start_time'=>'00:00','end_time'=>'23:59','max_attempts'=>3,'retry_minutes'=>1,'concurrency'=>2,'whatsapp_enabled'=>false,'whatsapp_after'=>1,'whatsapp_delay'=>1,'script'=>'Roteiro do teste']),'created_at'=>now(),'updated_at'=>now()]);
        foreach ($this->contacts as $id) DB::table('voice_members')->insert(['campaign_id'=>$this->campaign,'contact_id'=>$id]);
        $this->queue=$this->postJson('/api/voice/queues',$this->configuration())->assertOk()->json('id');
        $this->postJson('/api/voice/queues/'.$this->queue.'/populate')->assertOk()->assertJsonPath('added',3);
        $this->postJson('/api/voice/queues/'.$this->queue.'/status',['status'=>'running'])->assertOk();
        $this->presence();
    }

    private function user(int $workspace=1): User
    {
        $u=User::factory()->create();$u->forceFill(['voice_workspace_id'=>$workspace,'voice_role'=>'supervisor'])->save();return $u;
    }

    private function configuration(array $extra=[]): array
    {
        return array_replace(['name'=>'Fila QA','campaign_id'=>$this->campaign,'strategy'=>'fifo','wrapup_seconds'=>30,'agent_ids'=>[$this->agent->id,$this->other->id]],$extra);
    }

    private function presence(string $status='available'): void
    {
        $this->postJson('/api/voice/queues/presence',['status'=>$status,'pause_reason'=>$status==='paused'?'Teste de pausa':null])->assertNoContent();
    }

    private function claim(?string $key=null, ?int $queue=null)
    {
        return $this->postJson('/api/voice/queues/'.($queue??$this->queue).'/claim',['idempotency_key'=>$key??(string)Str::uuid()]);
    }

    private function finish(array $a, array $data=[])
    {
        return $this->postJson('/api/voice/queues/assignments/'.$a['id'].'/finish',array_replace(['outcome'=>'no_answer','notes'=>'Teste da fila'],$data));
    }

    public function test_population_is_idempotent_and_fifo_reserves_one_contact_without_provider_calls(): void
    {
        $this->postJson('/api/voice/queues/'.$this->queue.'/populate')->assertOk()->assertJsonPath('added',0);
        $key=(string)Str::uuid();$a=$this->claim($key)->assertOk()->json('assignment');
        $this->claim($key)->assertOk()->assertJsonPath('assignment.id',$a['id']);
        $this->getJson('/api/voice/queues')->assertOk()->assertJsonPath('mode','simulation')->assertJsonPath('current.contact.id',$this->contacts[0])->assertJsonPath('current.script','Roteiro do teste');
        $this->assertDatabaseCount('voice_queue_items',3);$this->assertDatabaseCount('voice_queue_assignments',1);$this->assertDatabaseCount('voice_attempts',1);$this->assertDatabaseCount('voice_outbound_calls',0);
        Http::assertNothingSent();
    }

    public function test_agents_do_not_share_contact_and_same_agent_cannot_claim_twice(): void
    {
        $a=$this->claim()->assertOk()->json('assignment');$this->claim()->assertConflict();
        $this->actingAs($this->other);$this->presence();$b=$this->claim()->assertOk()->json('assignment');
        $this->assertNotSame($a['item_id'],$b['item_id']);
        $this->finish($a)->assertForbidden();
        $this->assertDatabaseCount('voice_attempts',2);
    }

    public function test_non_member_other_workspace_and_anonymous_requests_are_blocked(): void
    {
        $u=$this->user();$this->actingAs($u);$this->presence();$this->claim()->assertForbidden();
        $workspace=DB::table('voice_workspaces')->insertGetId(['name'=>'Other']);$foreign=$this->user($workspace);$this->actingAs($foreign);
        $this->getJson('/api/voice/queues')->assertOk()->assertJsonCount(0,'queues')->assertJsonCount(0,'items')->assertJsonCount(0,'history');
        $this->claim()->assertNotFound();
        $this->postJson('/api/voice/queues/'.$this->queue.'/populate')->assertNotFound();
        $this->postJson('/api/voice/queues',$this->configuration())->assertNotFound();
        auth()->logout();$this->getJson('/api/voice/queues')->assertUnauthorized();
    }

    public function test_configuration_requires_workspace_agents_unique_campaign_and_simulation_mode(): void
    {
        $this->postJson('/api/voice/queues',$this->configuration())->assertConflict();
        $this->postJson('/api/voice/queues',$this->configuration(['mode'=>'live']))->assertStatus(422);
        $foreign=DB::table('voice_workspaces')->insertGetId(['name'=>'Other']);$u=$this->user($foreign);
        $paused=$this->postJson('/api/voice/queues/'.$this->queue.'/status',['status'=>'paused'])->assertOk()->json();
        $this->putJson('/api/voice/queues/'.$this->queue,$this->configuration(['revision'=>$paused['revision'],'agent_ids'=>[$u->id]]))->assertStatus(422);
        $this->putJson('/api/voice/queues/'.$this->queue,$this->configuration(['revision'=>0]))->assertConflict();
        $this->putJson('/api/voice/queues/'.$this->queue,$this->configuration(['revision'=>$paused['revision'],'name'=>'Atualizada']))->assertOk()->assertJsonPath('name','Atualizada');
    }

    public function test_priority_order_is_applied_only_when_strategy_is_priority(): void
    {
        $item=DB::table('voice_queue_items')->where('contact_id',$this->contacts[2])->first();
        $this->postJson('/api/voice/queues/items/'.$item->id.'/priority',['priority'=>100])->assertNoContent();
        $paused=$this->postJson('/api/voice/queues/'.$this->queue.'/status',['status'=>'paused'])->assertOk()->json();
        $this->putJson('/api/voice/queues/'.$this->queue,$this->configuration(['revision'=>$paused['revision'],'strategy'=>'priority']))->assertOk();
        $this->postJson('/api/voice/queues/'.$this->queue.'/status',['status'=>'running'])->assertOk();
        $this->claim()->assertOk()->assertJsonPath('assignment.item_id',$item->id);
        $this->postJson('/api/voice/queues/items/'.$item->id.'/priority',['priority'=>0])->assertConflict();
    }

    public function test_pause_stops_new_reservations_but_allows_completion(): void
    {
        $a=$this->claim()->assertOk()->json('assignment');
        $this->presence('paused');$this->postJson('/api/voice/queues/'.$this->queue.'/status',['status'=>'paused'])->assertOk();
        $this->finish($a)->assertOk();
        $this->actingAs($this->other);$this->presence();$this->claim()->assertConflict();
    }

    public function test_wrapup_blocks_next_contact_until_time_passes(): void
    {
        $a=$this->claim()->assertOk()->json('assignment');$this->finish($a)->assertOk();$this->claim()->assertConflict();
        $this->getJson('/api/voice/queues')->assertOk()->assertJsonPath('agents.0.status','wrapup');
        $this->travel(31)->seconds();$this->claim()->assertOk();
    }

    public function test_completion_is_immutable_including_notes_and_can_not_bypass_queue_controller(): void
    {
        $a=$this->claim()->assertOk()->json('assignment');
        $this->postJson('/api/voice/calls/'.$a['attempt_id'].'/finish',['outcome'=>'no_answer'])->assertConflict();
        $this->finish($a)->assertOk();$this->finish($a)->assertOk();$this->finish($a,['notes'=>'Alterada'])->assertConflict();
        $this->assertDatabaseCount('voice_queue_assignments',1);
    }

    public function test_scheduled_callback_respects_time_and_survives_page_reload(): void
    {
        DB::table('voice_queue_items')->where('contact_id','!=',$this->contacts[0])->delete();
        $a=$this->claim()->assertOk()->json('assignment');
        $this->finish($a,['outcome'=>'answered','qualification'=>'callback','callback_at'=>now()->addHour()->toIso8601String()])->assertOk();
        $this->assertNull(DB::table('voice_contacts')->find($this->contacts[0])->replied_at);
        $this->travel(31)->seconds();$this->claim()->assertOk()->assertJsonPath('assignment',null);
        $this->travel(60)->minutes();$this->presence();$this->claim()->assertOk()->assertJsonPath('assignment.item_id',$a['item_id']);
    }

    public function test_consent_suppression_and_removed_members_are_rechecked_before_assignment(): void
    {
        DB::table('voice_contacts')->where('id',$this->contacts[0])->update(['consent'=>false]);
        DB::table('voice_contacts')->where('id',$this->contacts[1])->update(['suppressed_at'=>now()]);
        DB::table('voice_members')->where('contact_id',$this->contacts[2])->delete();
        $this->claim()->assertOk()->assertJsonPath('assignment',null);
        $this->assertSame(3,DB::table('voice_queue_items')->where('status','blocked')->count());
        $this->assertDatabaseCount('voice_attempts',0);
    }

    public function test_contact_optout_releases_assignment_and_cancels_future_work(): void
    {
        $a=$this->claim()->assertOk()->json('assignment');
        $this->postJson('/api/voice/contacts/'.$this->contacts[0].'/stop',['type'=>'opt_out'])->assertOk();
        $this->getJson('/api/voice/queues')->assertOk()->assertJsonPath('current',null);
        $this->assertDatabaseHas('voice_queue_assignments',['id'=>$a['id'],'status'=>'cancelled']);
        $this->assertDatabaseHas('voice_queue_items',['id'=>$a['item_id'],'status'=>'blocked']);
        $this->finish($a)->assertConflict();
    }

    public function test_presence_loss_expires_simulation_and_a_late_result_cannot_override(): void
    {
        $a=$this->claim()->assertOk()->json('assignment');
        $this->travel(91)->seconds();
        $this->getJson('/api/voice/queues')->assertOk()->assertJsonPath('current',null)->assertJsonPath('agents.0.status','offline');
        $this->assertDatabaseHas('voice_attempts',['id'=>$a['attempt_id'],'status'=>'expired']);
        $this->finish($a)->assertConflict();
        $this->presence();$this->claim()->assertOk();
    }

    public function test_heartbeat_does_not_make_offline_agent_available_and_preserves_wrapup(): void
    {
        $this->presence('offline');$this->postJson('/api/voice/queues/heartbeat')->assertNoContent();$this->claim()->assertConflict();
        $this->presence();$a=$this->claim()->assertOk()->json('assignment');$this->finish($a)->assertOk();
        $this->presence('offline');$this->presence();$this->claim()->assertConflict();
    }

    public function test_campaign_pause_window_and_attempt_limit_are_enforced(): void
    {
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['status'=>'paused']);$this->claim()->assertConflict();
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['status'=>'testing']);
        $this->travelTo(now()->setTime(23,59));$this->presence();$this->claim()->assertStatus(422);
        $this->travelTo(now()->setTime(15,0));$this->presence();
        $s=json_decode(DB::table('voice_campaigns')->find($this->campaign)->settings,true);$s['max_attempts']=1;
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['settings'=>json_encode($s)]);
        $a=$this->claim()->assertOk()->json('assignment');$this->finish($a)->assertOk();
        $this->assertDatabaseHas('voice_queue_items',['id'=>$a['item_id'],'status'=>'blocked','reason'=>'Limite de tentativas atingido.']);
    }

    public function test_active_assignments_block_queue_edits(): void
    {
        $this->claim()->assertOk();$paused=$this->postJson('/api/voice/queues/'.$this->queue.'/status',['status'=>'paused'])->assertOk()->json();
        $this->putJson('/api/voice/queues/'.$this->queue,$this->configuration(['revision'=>$paused['revision']]))->assertConflict();
    }

    public function test_campaign_concurrency_still_applies_to_queue_claims(): void
    {
        $s=json_decode(DB::table('voice_campaigns')->find($this->campaign)->settings,true);$s['concurrency']=1;
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['settings'=>json_encode($s)]);
        $this->claim()->assertOk();$this->actingAs($this->other);$this->presence();$this->claim()->assertConflict();
        $this->assertDatabaseCount('voice_queue_assignments',1);
    }

    public function test_real_call_cannot_start_for_agent_or_contact_reserved_in_simulated_queue(): void
    {
        config(['voice_calling_test'=>['allowed_recipients'=>['+5511999990001'],'daily_limit'=>20,'max_seconds'=>30,'ring_seconds'=>10,'sip_username'=>'fixture','sip_password'=>'fixture-only','event_secret'=>str_repeat('a',64)],'twilio_voice_test'=>['account_sid'=>'AC'.str_repeat('1',32),'api_key'=>'SK'.str_repeat('2',32),'api_secret'=>'fixture-secret-only','auth_token'=>'fixture-token-only','application_sid'=>'AP'.str_repeat('3',32),'caller_id'=>'+551130000001','edge'=>'sao-paulo','enabled'=>true]]);
        $this->claim()->assertOk();
        foreach ([$this->agent,$this->other] as $u) {
            $this->actingAs($u)->postJson('/api/voice/calling/calls',['method'=>'programmable_voice','contact_id'=>$this->contacts[0],'idempotency_key'=>(string)Str::uuid(),'consent_confirmed'=>true,'consent_evidence'=>'Fixture only'])->assertConflict();
        }
        $this->assertDatabaseCount('voice_outbound_calls',0);Http::assertNothingSent();
    }
}
