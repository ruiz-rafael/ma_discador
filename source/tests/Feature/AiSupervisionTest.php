<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\AiAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class AiSupervisionTest extends TestCase
{
    use RefreshDatabase;
    private string $agent;
    protected function setUp():void
    {
        parent::setUp();Http::preventStrayRequests();$this->travelTo(now()->setDate(2026,10,17)->setTime(15,0));
        DB::table('voice_workspaces')->insert(['id'=>2,'name'=>'Outra empresa']);
        $u=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin']);$this->actingAs($u);
        $this->agent=app(AiAgents::class)->save(1,$u->id,['config'=>array_replace(AiAgents::defaults(),['name'=>'Sofia'])])['id'];
    }
    private function conversation(array $changes=[]):string
    {
        $id=(string)Str::uuid();DB::table('ai_sessions')->insert(array_replace(['id'=>$id,'workspace_id'=>1,'agent_id'=>$this->agent,'channel'=>'whatsapp','mode'=>'premium','config'=>json_encode(array_replace(AiAgents::defaults(),['name'=>'Sofia','rules'=>'INTERNAL_INSTRUCTIONS','knowledge'=>[['question'=>'private','answer'=>'SECRET_COMPANY_DATA']]])),'revision'=>1,'status'=>'open','expires_at'=>now()->addHour(),'created_at'=>now(),'updated_at'=>now()],$changes));return $id;
    }
    private function turn(string $s,array $changes=[]):void
    {
        DB::table('ai_turns')->insert(array_replace(['id'=>(string)Str::uuid(),'session_id'=>$s,'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'sequence'=>1,'input'=>'Preciso de ajuda','output'=>'Vou chamar a equipe','status'=>'completed','action'=>'reply','latency_ms'=>200,'created_at'=>now(),'updated_at'=>now()],$changes));
    }
    public function test_roles_and_tenant_isolation_for_dashboard_and_transcripts():void
    {
        $mine=$this->conversation();$other=$this->conversation(['workspace_id'=>2]);$this->turn($other,['input'=>'OTHER_TENANT_PRIVATE']);
        $supervisor=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'supervisor']);$this->actingAs($supervisor);
        $this->getJson('/api/ai/supervision')->assertOk()->assertJsonPath('summary.sessions',1)->assertDontSee('OTHER_TENANT_PRIVATE');
        $this->getJson('/api/ai/supervision/sessions/'.$mine)->assertOk()->assertDontSee('INTERNAL_INSTRUCTIONS')->assertDontSee('SECRET_COMPANY_DATA');
        $this->getJson('/api/ai/supervision/sessions/'.$other)->assertNotFound();
        $this->getJson('/api/ai/agents')->assertForbidden();$this->postJson('/api/ai/agents/'.$this->agent.'/pause',['paused'=>true])->assertForbidden();
        $this->actingAs(User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'agent']));$this->getJson('/api/ai/supervision')->assertForbidden();$this->getJson('/api/ai/supervision/sessions/'.$mine)->assertForbidden();
        Http::assertNothingSent();
    }
    public function test_tests_are_separate_and_voice_cost_is_unknown_instead_of_zero():void
    {
        $this->conversation(['estimated_usd'=>0.012,'input_tokens'=>100,'output_tokens'=>200]);
        $this->conversation(['channel'=>'phone','estimated_usd'=>123]);
        $text=$this->conversation(['channel'=>'text','estimated_usd'=>0.007]);$this->turn($text,['latency_ms'=>600]);
        $this->conversation(['channel'=>'text','mode'=>'local']);$this->conversation(['channel'=>'voice']);
        $live=$this->getJson('/api/ai/supervision')->assertOk()->assertJsonPath('summary.sessions',2)->assertJsonPath('summary.text_estimated_usd',0.012)->assertJsonPath('summary.voice_estimated_usd',null)->assertJsonPath('summary.text_latency_ms',null)->json();
        $this->assertNull(collect($live['sessions']['data'])->firstWhere('channel','phone')['estimated_usd']);
        $this->getJson('/api/ai/supervision?scope=tests')->assertOk()->assertJsonPath('summary.sessions',3)->assertJsonPath('summary.text_estimated_usd',0.007)->assertJsonPath('summary.text_latency_ms',600)->assertJsonPath('summary.text_latency_samples',1);
        Http::assertNothingSent();
    }
    public function test_outcomes_use_exists_so_multiple_events_do_not_duplicate_sessions_or_costs():void
    {
        $s=$this->conversation(['status'=>'closed','estimated_usd'=>0.05]);$this->turn($s,['action'=>'handoff']);$this->turn($s,['sequence'=>2,'status'=>'failed','action'=>null]);
        foreach(['handoff','failed'] as $status)DB::table('ai_whatsapp_jobs')->insert(['message_id'=>(string)Str::uuid(),'workspace_id'=>1,'conversation_id'=>(string)Str::uuid(),'agent_id'=>$this->agent,'session_id'=>$s,'status'=>$status,'created_at'=>now(),'updated_at'=>now()]);
        $this->getJson('/api/ai/supervision')->assertOk()->assertJsonPath('summary.sessions',1)->assertJsonPath('summary.handoff',1)->assertJsonPath('summary.failure',1)->assertJsonPath('summary.closed',1)->assertJsonPath('summary.text_estimated_usd',0.05);
        $this->getJson('/api/ai/supervision?outcome=handoff')->assertOk()->assertJsonPath('sessions.total',1);
        $this->getJson('/api/ai/supervision?outcome=failure')->assertOk()->assertJsonPath('sessions.total',1);
        $this->assertDatabaseCount('ai_sessions',1);Http::assertNothingSent();
    }
    public function test_voice_transfer_remains_visible_after_session_closes_but_is_not_confirmed_as_answered():void
    {
        $s=$this->conversation(['channel'=>'phone','status'=>'closed']);DB::table('voice_audit')->insert(['workspace_id'=>1,'event'=>'ai.voice.transfer','subject_id'=>$s,'data'=>json_encode(['request_accepted'=>true,'target'=>'PRIVATE_SIP_URI']),'created_at'=>now()]);
        $this->getJson('/api/ai/supervision?outcome=handoff')->assertOk()->assertJsonPath('summary.handoff',1);
        $this->getJson('/api/ai/supervision/sessions/'.$s)->assertOk()->assertJsonPath('transfers.0.provider_accepted',true)->assertJsonPath('session.estimated_usd',null)->assertDontSee('PRIVATE_SIP_URI');
        DB::table('voice_audit')->where('subject_id',$s)->update(['data'=>json_encode(['request_accepted'=>false])]);
        $this->getJson('/api/ai/supervision?outcome=failure')->assertOk()->assertJsonPath('summary.failure',1);
    }
    public function test_expired_sessions_are_not_counted_as_active_and_reading_does_not_close_them():void
    {
        $s=$this->conversation(['expires_at'=>now()->subSecond()]);$this->conversation(['status'=>'connecting']);$this->getJson('/api/ai/supervision')->assertOk()->assertJsonPath('summary.active',1)->assertJsonPath('summary.expired',1);
        $this->getJson('/api/ai/supervision?outcome=expired')->assertOk()->assertJsonPath('sessions.total',1)->assertJsonPath('sessions.data.0.expired',true);
        $this->assertDatabaseHas('ai_sessions',['id'=>$s,'status'=>'open']);Http::assertNothingSent();
    }
    public function test_day_boundaries_use_brasilia_and_filters_are_validated():void
    {
        $this->conversation(['created_at'=>'2026-10-17 02:59:59']);$this->conversation(['created_at'=>'2026-10-17 03:00:00']);$this->conversation(['created_at'=>'2026-10-18 02:59:59']);$this->conversation(['created_at'=>'2026-10-18 03:00:00']);
        $this->getJson('/api/ai/supervision?from=2026-10-17&to=2026-10-17')->assertOk()->assertJsonPath('summary.sessions',2)->assertJsonPath('series.0.date','2026-10-17')->assertJsonPath('series.0.total',2);
        foreach(['from=2026-01-01&to=2026-10-17','from=2026-10-18&to=2026-10-17','scope=production&channel=voice','scope=tests&channel=phone','page=0','outcome=resolved','from=bad'] as $query)$this->getJson('/api/ai/supervision?'.$query)->assertUnprocessable();
        $this->getJson('/api/ai/supervision?agent_id='.(string)Str::uuid())->assertNotFound();
    }
    public function test_pagination_is_stable_and_transcripts_do_not_leak_provider_configuration():void
    {
        for($i=0;$i<27;$i++)$this->conversation();
        $p1=$this->getJson('/api/ai/supervision')->assertOk()->assertJsonPath('sessions.total',27)->assertJsonCount(25,'sessions.data')->json('sessions.data');
        $p2=$this->getJson('/api/ai/supervision?page=2')->assertOk()->assertJsonCount(2,'sessions.data')->json('sessions.data');
        $this->assertCount(27,array_unique(array_column(array_merge($p1,$p2),'id')));
        $s=$p1[0]['id'];DB::table('ai_sessions')->where('id',$s)->update(['provider_call_id'=>'PRIVATE_PROVIDER_ID']);for($i=1;$i<=32;$i++)$this->turn($s,['sequence'=>$i,'input'=>'Mensagem '.$i]);
        $this->getJson('/api/ai/supervision/sessions/'.$s)->assertOk()->assertJsonCount(30,'turns.data')->assertJsonPath('turns.data.0.input','Mensagem 1')->assertDontSee('PRIVATE_PROVIDER_ID')->assertDontSee('request_hash')->assertDontSee('SECRET_COMPANY_DATA');
        $this->getJson('/api/ai/supervision/sessions/'.$s.'?page=2')->assertOk()->assertJsonCount(2,'turns.data')->assertJsonPath('turns.data.0.input','Mensagem 31');
    }
}
