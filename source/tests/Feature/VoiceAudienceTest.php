<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\{VoiceAudience,VoiceJourneyPreset,VoiceLiveQueue,ListContacts};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceAudienceTest extends TestCase
{
    use RefreshDatabase;
    private User $user;
    private int $campaign;
    private int $audience;
    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests(); Queue::fake();
        $this->travelTo(now()->setDate(2026,10,5)->setTime(15,0));
        $this->user = User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin']); $this->actingAs($this->user);
        $this->campaign = app(VoiceJourneyPreset::class)->create(1,$this->user->id,'+12025550123')['campaign_id'];
        $this->audience = DB::table('audiences')->insertGetId(['name'=>'Lista Teste']);
    }
    private function source(?string $phone='+5511999990001', bool $consent=true, ?string $evidence='Autorização documentada'): int
    {
        $id=DB::table('contacts')->insertGetId(['name'=>'Ana MA','phone'=>$phone,'email'=>'ana@example.test','subscribed'=>$consent,'fields'=>json_encode(['empresa'=>'Zyrex','consent_evidence'=>$evidence])]);
        DB::table('audience_contact')->insert(['audience_id'=>$this->audience,'contact_id'=>$id]);return $id;
    }
    private function select(int $revision=0)
    {
        return $this->putJson('/api/voice/journeys/'.$this->campaign.'/nodes/entry',['revision'=>$revision,'config'=>['list_kind'=>'automation','list_id'=>$this->audience]]);
    }
    private function policy(): object { return DB::table('voice_campaign_policies')->where('campaign_id',$this->campaign)->first(); }
    private function sync(): void { DB::transaction(function(){app(ListContacts::class)->lock();app(VoiceAudience::class)->sync(1,$this->campaign);}); }

    public function test_catalog_contains_both_kinds_and_list_ids_never_collide(): void
    {
        $list=DB::table('voice_campaign_policies')->where('campaign_id',$this->campaign)->value('list_id');
        $data=$this->getJson('/api/voice')->assertOk()->json('journey_lists');
        $this->assertTrue(collect($data)->contains(fn($l)=>$l['kind']==='automation'&&$l['id']===$this->audience));
        $this->assertTrue(collect($data)->contains(fn($l)=>$l['kind']==='voice'&&$l['id']===$list));
        $this->select()->assertOk()->assertJsonPath('journey.list.kind','automation')->assertJsonPath('journey.list.name','Lista Teste');
        $this->assertNull($this->policy()->list_id);$this->assertSame($this->audience,$this->policy()->audience_id);
        Http::assertNothingSent();Queue::assertNothingPushed();
    }
    public function test_selection_skips_bad_phones_preserves_existing_voice_identity_and_source_data(): void
    {
        $source=$this->source('(11) 99999-0001');$this->source(null);$this->source('=12345678');$this->source('+5511999990002',false);
        $existing=DB::table('voice_contacts')->insertGetId(['workspace_id'=>1,'name'=>'Nome preservado','phone'=>'+5511999990001','original_phone'=>'+5511999990001','source'=>'Anterior','consent'=>true,'consent_evidence'=>'Anterior','suppressed_at'=>now()]);
        $this->select()->assertOk()->assertJsonPath('journey.contact_count',2)->assertJsonPath('journey.status','paused');
        $this->assertDatabaseHas('voice_members',['campaign_id'=>$this->campaign,'contact_id'=>$existing]);
        $this->assertDatabaseHas('voice_contacts',['id'=>$existing,'name'=>'Nome preservado']);$this->assertNotNull(DB::table('voice_contacts')->find($existing)->suppressed_at);
        $this->assertDatabaseHas('voice_contacts',['phone'=>'+5511999990002','consent'=>false]);
        $this->assertDatabaseHas('contacts',['id'=>$source,'name'=>'Ana MA','phone'=>'(11) 99999-0001']);
        $this->assertDatabaseCount('voice_outbound_calls',0);$this->assertDatabaseCount('wa_messages',0);
        $this->select(1)->assertOk();$this->assertDatabaseCount('voice_contacts',2);
    }
    public function test_next_queue_claim_refreshes_new_members_without_calling_and_source_removal_blocks_immediately(): void
    {
        $this->select()->assertOk();$id=$this->source();
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['status'=>'testing']);
        $queue=DB::table('voice_live_queues')->where('campaign_id',$this->campaign)->first();
        DB::table('voice_live_queues')->where('id',$queue->id)->update(['status'=>'running']);
        DB::table('voice_agent_presence')->insert(['workspace_id'=>1,'user_id'=>$this->user->id,'status'=>'available','last_seen_at'=>now()]);
        $r=app(VoiceLiveQueue::class)->claim(1,$this->user->id,$queue->id,(string)Str::uuid());$this->assertNotNull($r['reservation']);
        $c=DB::table('voice_contacts')->find($r['reservation']->contact_id);
        $this->assertNull(app(VoiceAudience::class)->reason(1,$this->policy(),$c));
        app(ListContacts::class)->remove(1,'automation',$this->audience,$id);
        $this->assertNotNull(app(VoiceAudience::class)->reason(1,$this->policy(),$c));
        $this->sync();$this->assertDatabaseMissing('voice_members',['campaign_id'=>$this->campaign,'contact_id'=>$c->id]);
        $this->assertDatabaseHas('voice_contacts',['id'=>$c->id]);Http::assertNothingSent();Queue::assertNothingPushed();
    }
    public function test_source_consent_and_number_are_rechecked_including_new_duplicate_without_resync(): void
    {
        $id=$this->source();$this->select()->assertOk();$c=DB::table('voice_contacts')->first();
        DB::table('contacts')->where('id',$id)->update(['subscribed'=>false]);
        $this->assertStringContainsString('autorização',app(VoiceAudience::class)->reason(1,$this->policy(),$c));
        DB::table('contacts')->where('id',$id)->update(['subscribed'=>true,'phone'=>'+5511999990003']);
        $this->assertStringContainsString('telefone',app(VoiceAudience::class)->reason(1,$this->policy(),$c));
        DB::table('contacts')->where('id',$id)->update(['phone'=>$c->phone]);$this->source($c->phone,false);
        $this->assertStringContainsString('autorização',app(VoiceAudience::class)->reason(1,$this->policy(),$c));
    }
    public function test_missing_evidence_and_exclusions_are_not_upgraded_to_voice_permission(): void
    {
        $excluded=$this->source();DB::table('ma_list_membership_exclusions')->insert(['audience_id'=>$this->audience,'contact_id'=>$excluded,'created_at'=>now()]);
        $this->source('+5511999990002',true,null);$this->select()->assertOk()->assertJsonPath('journey.contact_count',1);
        $this->assertDatabaseHas('voice_contacts',['phone'=>'+5511999990002','consent'=>false]);
        $this->assertDatabaseMissing('voice_contacts',['phone'=>'+5511999990001']);
    }
    public function test_invalid_workspace_revision_and_running_campaign_cannot_create_bindings(): void
    {
        $this->source();$this->select(99)->assertConflict();$this->assertDatabaseCount('voice_contacts',0);
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['status'=>'testing']);$this->select()->assertConflict();$this->assertDatabaseCount('voice_contacts',0);$this->assertDatabaseCount('voice_audience_contacts',0);
        DB::table('voice_workspaces')->insert(['id'=>2,'name'=>'Outro']);$this->user->forceFill(['voice_workspace_id'=>2])->save();
        $this->assertSame([],array_values(array_filter(app(VoiceAudience::class)->catalog(2),fn($l)=>$l['kind']==='automation')));
        $this->select()->assertNotFound();
    }
    public function test_switching_to_individual_selection_clears_source_and_large_lists_are_rejected_atomically(): void
    {
        $this->source();$this->select()->assertOk();$id=DB::table('voice_contacts')->value('id');
        $this->putJson('/api/voice/journeys/'.$this->campaign.'/nodes/entry',['revision'=>1,'config'=>['list_id'=>null,'contact_ids'=>[$id]]])->assertOk()->assertJsonPath('journey.list',null);
        $this->assertNull($this->policy()->audience_id);
        for($i=0;$i<500;$i++)$this->source(null);
        $this->select(2)->assertUnprocessable();$this->assertNull($this->policy()->audience_id);$this->assertDatabaseCount('voice_contacts',1);
    }

    public function test_current_ma_name_is_used_without_overwriting_voice_identity_or_consent(): void
    {
        $source=$this->source();
        $id=DB::table('voice_contacts')->insertGetId(['workspace_id'=>1,'name'=>'Teste antigo','phone'=>'+5511999990001','original_phone'=>'+5511999990001','source'=>'Legado','consent'=>true,'consent_evidence'=>'Anterior']);
        $this->select()->assertOk();
        $campaign=DB::table('voice_campaigns')->find($this->campaign);$contact=DB::table('voice_contacts')->find($id);
        $p=app(\App\Services\VoicePersonalization::class);
        $this->assertSame('Olá, Ana MA / Ana!', $p->render('Olá, {nome} / {primeiro_nome}!', $contact,$campaign));
        DB::table('contacts')->where('id',$source)->update(['name'=>'Ana Atualizada']);
        $this->assertSame('Ana Atualizada',$p->render('{nome}',$contact,$campaign));
        $this->assertDatabaseHas('voice_contacts',['id'=>$id,'name'=>'Teste antigo','consent_evidence'=>'Anterior']);
        Http::assertNothingSent();
    }

}
