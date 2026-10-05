<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\JourneyReplyAttribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class JourneyReportsTest extends TestCase
{
    use RefreshDatabase;
    private User $user;
    private int $contact; private int $campaign; private int $sender;
    protected function setUp(): void
    {
        parent::setUp();Http::preventStrayRequests();$this->travelTo(now()->setDate(2026,10,4)->setTime(18,0));
        $this->user=User::factory()->create();$this->user->forceFill(['voice_workspace_id'=>1,'voice_role'=>'admin'])->save();$this->actingAs($this->user);
        $this->contact=DB::table('voice_contacts')->insertGetId(['workspace_id'=>1,'name'=>'Rafael','phone'=>'+5511999990001','original_phone'=>'+5511999990001','source'=>'QA','consent'=>true]);
        $this->campaign=DB::table('voice_campaigns')->insertGetId(['workspace_id'=>1,'name'=>'Jornada QA','settings'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        $this->sender=DB::table('wa_senders')->insertGetId(['workspace_id'=>1,'label'=>'QA','number'=>'+5511999990000','ownership'=>'external','provider'=>'qr']);
    }
    private function message(array $extra=[]): string
    {
        $id=(string)Str::uuid();DB::table('wa_messages')->insert(array_replace(['id'=>$id,'workspace_id'=>1,'sender_id'=>$this->sender,'contact_id'=>$this->contact,'campaign_id'=>$this->campaign,'provider'=>'qr','direction'=>'outbound','idempotency_key'=>$id,'request_hash'=>hash('sha256',$id),'account_sid'=>'qr:'.$this->sender,'from_number'=>'+5511999990000','to_number'=>'+5511999990001','status'=>'sent','body'=>'Olá, Rafael','provider_reference'=>$id,'created_at'=>now()->subMinutes(10),'updated_at'=>now()],$extra));return $id;
    }
    private function reply(array $extra=[]): string
    {
        return $this->message(array_replace(['direction'=>'inbound','campaign_id'=>null,'from_number'=>'+5511999990001','to_number'=>'+5511999990000','status'=>'received','body'=>'Olá!','created_at'=>now()],$extra));
    }
    private function callRecord(string $status,array $extra=[]): string
    {
        $id=(string)Str::uuid();DB::table('voice_outbound_calls')->insert(array_replace(['id'=>$id,'workspace_id'=>1,'user_id'=>$this->user->id,'contact_id'=>$this->contact,'campaign_id'=>$this->campaign,'idempotency_key'=>$id,'request_hash'=>str_repeat('a',64),'grant_hash'=>hash('sha256',$id),'configuration_hash'=>str_repeat('b',64),'destination'=>'+5511999990001','caller_id'=>'+551130000001','method'=>'programmable_voice','provider_account'=>'AC'.str_repeat('1',32),'status'=>$status,'max_seconds'=>30,'ring_seconds'=>10,'consent_evidence'=>'QA','grant_expires_at'=>now(),'deadline_at'=>now(),'started_at'=>now()->subMinutes(10),'created_at'=>now()->subMinutes(10),'updated_at'=>now()],$extra));return $id;
    }
    private function url(string $path='',array $f=[]): string
    {
        return '/api/voice/journey-reports'.$path.'?'.http_build_query(array_replace(['from'=>'2026-10-04','to'=>'2026-10-04','campaign_id'=>$this->campaign],$f));
    }
    public function test_counts_real_calls_and_cumulative_delivery_without_double_counting(): void
    {
        $this->callRecord('completed',['human_confirmed'=>true]);$this->callRecord('no_answer');$this->callRecord('no_answer');$this->callRecord('busy');$this->callRecord('failed',['started_at'=>null]);
        foreach(['read','delivered','failed','unknown','cancelled'] as $status)$this->message(['status'=>$status]);
        $response=$this->getJson($this->url())->assertOk()->assertJsonPath('summary.calls',4)->assertJsonPath('summary.answered',1)->assertJsonPath('summary.no_answer',2)->assertJsonPath('summary.call_failed',1)->assertJsonPath('summary.call_other',1)->assertJsonPath('summary.called_contacts',1)->assertJsonPath('summary.human_contacts',1)->assertJsonPath('summary.dispatches',4)->assertJsonPath('summary.delivered',2)->assertJsonPath('summary.read',1)->assertJsonPath('summary.message_failed',1)->assertJsonPath('summary.message_pending',1)->assertJsonPath('summary.button_clicks',0)->assertJsonPath('timeline.0.calls',4);
        foreach(['calls','answered','no_answer','call_failed','call_other','dispatches','delivered','read','message_failed','message_pending'] as $metric)$this->getJson($this->url('/details',['metric'=>$metric]))->assertOk()->assertJsonPath('rows.total',$response->json('summary.'.$metric));
        Http::assertNothingSent();
    }
    public function test_period_uses_sao_paulo_boundaries_and_day_drill_matches_chart(): void
    {
        $this->message(['created_at'=>'2026-10-04 02:59:59']);$this->message(['created_at'=>'2026-10-04 03:00:00']);$this->message(['created_at'=>'2026-10-05 02:59:59']);$this->message(['created_at'=>'2026-10-05 03:00:00']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('summary.dispatches',2)->assertJsonPath('timeline.0.dispatches',2);
        $this->getJson($this->url('/details',['metric'=>'dispatches','day'=>'2026-10-04']))->assertOk()->assertJsonPath('rows.total',2);
        $this->getJson($this->url('/details',['metric'=>'dispatches','day'=>'2026-10-03']))->assertUnprocessable();
    }
    public function test_exact_reply_button_is_attributed_and_duplicate_processing_does_not_add_clicks(): void
    {
        $out=$this->message(['interactive'=>json_encode(['buttons'=>[['type'=>'reply','id'=>'agora','label'=>'Podemos falar agora']]])]);
        $in=$this->reply(['interactive'=>json_encode(['kind'=>'button_reply','id'=>'agora','label'=>'Título forjado','context_id'=>$out,'matched_message_id'=>$out])]);
        $s=app(JourneyReplyAttribution::class);$s->record($in);$s->record($in);
        $this->getJson($this->url())->assertOk()->assertJsonPath('summary.replies',1)->assertJsonPath('summary.button_clicks',1)->assertJsonPath('buttons.0.button_label','Podemos falar agora');
        $this->getJson($this->url('/details',['metric'=>'button_clicks','button_id'=>'agora']))->assertOk()->assertJsonPath('rows.total',1)->assertJsonPath('rows.data.0.reply_attribution','context');
        $this->getJson('/api/voice/journey-reports/records/messages/'.$in)->assertOk()->assertJsonPath('original_message.id',$out);
    }
    public function test_ambiguous_reply_is_unattributed_and_typed_button_title_is_not_a_click(): void
    {
        $this->message();$in=$this->reply(['body'=>'Podemos falar agora']);app(JourneyReplyAttribution::class)->record($in);
        $this->assertDatabaseHas('wa_messages',['id'=>$in,'reply_attribution'=>'single_journey','button_id'=>null,'campaign_id'=>$this->campaign]);
        $other=DB::table('voice_campaigns')->insertGetId(['workspace_id'=>1,'name'=>'Outra','settings'=>'{}']);$this->message(['campaign_id'=>$other]);
        $ambiguous=$this->reply();app(JourneyReplyAttribution::class)->record($ambiguous);
        $this->getJson($this->url())->assertOk()->assertJsonPath('summary.replies',1)->assertJsonPath('summary.button_clicks',0);
        $this->getJson($this->url('',['campaign_id'=>null]))->assertOk()->assertJsonPath('summary.replies',2)->assertJsonPath('summary.unattributed',1);
        $this->assertDatabaseHas('wa_messages',['id'=>$ambiguous,'reply_attribution'=>'unattributed','campaign_id'=>null]);
    }
    public function test_context_cannot_cross_recipient_sender_or_time_and_missing_context_never_guesses_button(): void
    {
        $out=$this->message(['to_number'=>'+5511999990002','interactive'=>json_encode(['buttons'=>[['type'=>'reply','id'=>'agora','label'=>'Agora']]])]);
        foreach([['kind'=>'button_reply','id'=>'agora','context_id'=>$out,'matched_message_id'=>$out],['kind'=>'button_reply','id'=>'agora']] as $i){$in=$this->reply(['interactive'=>json_encode($i)]);app(JourneyReplyAttribution::class)->record($in);$this->assertDatabaseHas('wa_messages',['id'=>$in,'campaign_id'=>null,'button_id'=>null]);}
        $this->message(['created_at'=>now()->addMinutes(1)]);$in=$this->reply();app(JourneyReplyAttribution::class)->record($in);$this->assertDatabaseHas('wa_messages',['id'=>$in,'campaign_id'=>null]);
    }
    public function test_report_authorization_workspace_scope_and_secret_redaction(): void
    {
        $id=$this->callRecord('completed');$this->getJson('/api/voice/journey-reports/records/calls/'.$id)->assertOk()->assertDontSee('grant_hash')->assertDontSee('configuration_hash')->assertDontSee('provider_account');
        DB::table('voice_workspaces')->insert(['id'=>2,'name'=>'Outro']);$other=DB::table('voice_campaigns')->insertGetId(['workspace_id'=>2,'name'=>'Privada','settings'=>'{}']);$otherCall=$this->callRecord('completed',['workspace_id'=>2,'campaign_id'=>$other]);
        $this->getJson($this->url('',['campaign_id'=>$other]))->assertNotFound();$this->getJson('/api/voice/journey-reports/records/calls/'.$otherCall)->assertNotFound();
        $this->user->forceFill(['voice_role'=>'agent'])->save();$this->getJson($this->url())->assertForbidden();$this->getJson($this->url('/details',['metric'=>'calls']))->assertForbidden();
        Http::assertNothingSent();
    }
    public function test_pagination_filters_empty_ranges_and_parameter_validation(): void
    {
        for($i=0;$i<27;$i++)$this->message();
        $this->getJson($this->url('/details',['metric'=>'dispatches']))->assertOk()->assertJsonPath('rows.total',27)->assertJsonCount(25,'rows.data');
        $this->getJson($this->url('/details',['metric'=>'dispatches','page'=>2]))->assertOk()->assertJsonCount(2,'rows.data');
        $this->getJson($this->url('/details',['metric'=>'dispatches','phone'=>'8888']))->assertOk()->assertJsonPath('rows.total',0);
        $this->getJson($this->url('',['from'=>'2026-10-03','to'=>'2026-10-03']))->assertOk()->assertJsonPath('summary.dispatches',0)->assertJsonPath('timeline.0.dispatches',0);
        $this->getJson($this->url('',['from'=>'2025-01-01']))->assertUnprocessable();
        $this->getJson($this->url('/details',['metric'=>'invalid']))->assertUnprocessable();
        $this->getJson($this->url('/details',['metric'=>'calls','button_id'=>'x']))->assertUnprocessable();
    }
    public function test_backfill_is_idempotent_and_preserves_original_event_timestamps(): void
    {
        $this->message();$in=$this->reply();$before=DB::table('wa_messages')->find($in);
        $this->artisan('ma:backfill-journey-replies')->assertSuccessful();$this->artisan('ma:backfill-journey-replies')->assertSuccessful();
        $after=DB::table('wa_messages')->find($in);$this->assertSame($before->created_at,$after->created_at);$this->assertSame($before->updated_at,$after->updated_at);$this->assertSame('single_journey',$after->reply_attribution);$this->assertDatabaseCount('wa_messages',2);Http::assertNothingSent();
    }
}
