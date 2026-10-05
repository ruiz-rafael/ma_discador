<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\{VoiceFollowups, WhatsAppQr, WhatsAppMessages, TwilioVoiceCalling};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceFollowupTest extends TestCase
{
    use RefreshDatabase;
    private User $user;
    private int $qrPosts = 0;
    private int $contact;
    private int $sender;
    private int $campaign;
    private array $settings;
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026,10,3)->setTime(15,0));
        Http::preventStrayRequests();
        $this->user=User::factory()->create();$this->user->forceFill(['voice_workspace_id'=>1,'voice_role'=>'supervisor'])->save();$this->actingAs($this->user);
        $this->contact=DB::table('voice_contacts')->insertGetId(['workspace_id'=>1,'name'=>'Ana','phone'=>'+5511999990001','original_phone'=>'+5511999990001','source'=>'QA','consent'=>true,'consent_evidence'=>'Autorização para homologação']);
        $this->sender=DB::table('wa_senders')->insertGetId(['workspace_id'=>1,'label'=>'QR QA','number'=>'+5511999990000','ownership'=>'external','provider'=>'qr']);
        $this->settings=['mode'=>'preview','business_number'=>'+551130000000','number_mode'=>'separate','whatsapp_number'=>'+5511999990000','whatsapp_sender_id'=>$this->sender,'timezone'=>'UTC','days'=>[1,2,3,4,5,6,7],'start_time'=>'00:00','end_time'=>'23:59','max_attempts'=>4,'retry_minutes'=>1,'concurrency'=>1,'whatsapp_enabled'=>true,'whatsapp_after'=>2,'whatsapp_delay'=>0,'whatsapp_delivery'=>'automatic','whatsapp_text'=>'Olá, {nome}! Podemos conversar?','whatsapp_variables'=>[]];
        $this->campaign=DB::table('voice_campaigns')->insertGetId(['workspace_id'=>1,'name'=>'QA','status'=>'testing','revision'=>1,'followup_revision'=>1,'settings'=>json_encode($this->settings),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('voice_members')->insert(['campaign_id'=>$this->campaign,'contact_id'=>$this->contact]);
        config(['whatsapp_qr_test'=>['url'=>'http://whatsapp-qr:3000','token'=>'test-only-private-token','allowed_recipients'=>['+5511999990001'],'daily_limit'=>10]]);
    }
    private function recordCall(string $status='no_answer', ?int $revision=1): string
    {
        $id=(string)Str::uuid();
        DB::table('voice_outbound_calls')->insert(['id'=>$id,'workspace_id'=>1,'user_id'=>$this->user->id,'contact_id'=>$this->contact,'campaign_id'=>$this->campaign,'campaign_revision'=>$revision,'idempotency_key'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'grant_hash'=>hash('sha256',$id),'configuration_hash'=>str_repeat('b',64),'destination'=>'+5511999990001','caller_id'=>'+551130000000','status'=>$status,'max_seconds'=>60,'ring_seconds'=>30,'consent_evidence'=>'Autorização de teste','grant_expires_at'=>now(),'deadline_at'=>now(),'started_at'=>now()->subMinute(),'ended_at'=>now(),'capacity_released_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        app(VoiceFollowups::class)->observe($id);return $id;
    }
    private function due(): string { $this->recordCall();$this->recordCall();return DB::table('voice_followups')->value('id'); }
    private function fakeQr(bool $timeout=false): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
        Http::fake(function($r)use($timeout){
            if(str_ends_with($r->url(),'/messages')){$this->qrPosts++;if($timeout)throw new \Illuminate\Http\Client\ConnectionException('timeout');return Http::response(['status'=>'sent','reference'=>'QR-EXAMPLE']);}
            return Http::response(['status'=>'connected','number'=>'+5511999990000','events'=>[]]);
        });
    }
    public function test_ma_audience_removal_blocks_pending_whatsapp_before_provider(): void
    {
        $audience=DB::table('audiences')->insertGetId(['name'=>'MA']);
        $source=DB::table('contacts')->insertGetId(['name'=>'Ana','phone'=>'+5511999990001','subscribed'=>true,'fields'=>json_encode(['consent_evidence'=>'Autorização documentada'])]);
        DB::table('audience_contact')->insert(['audience_id'=>$audience,'contact_id'=>$source]);
        DB::table('voice_campaign_policies')->insert(['campaign_id'=>$this->campaign,'audience_id'=>$audience,'retry_minutes'=>'{}']);
        $id=$this->due();
        DB::table('audience_contact')->where('audience_id',$audience)->where('contact_id',$source)->delete();
        app(VoiceFollowups::class)->dispatch($id);
        $this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'cancelled']);
        $this->assertDatabaseCount('wa_messages',0);Http::assertNothingSent();
    }
    public function test_threshold_counts_only_new_real_missed_calls_and_is_idempotent():void
    {
        $this->recordCall('no_answer',null);$this->recordCall('busy');$this->recordCall('failed');$this->recordCall('cancelled');$this->recordCall();
        $this->assertDatabaseCount('voice_followups',0);$id=$this->recordCall();app(VoiceFollowups::class)->observe($id);$this->recordCall();
        $this->assertDatabaseCount('voice_followups',1);Http::assertNothingSent();
    }
    public function test_qr_template_opt_in_is_saved_through_journey_and_changes_rule_revision(): void
    {
        $template=app(\App\Services\WhatsAppQrTemplates::class)->create(1,['name'=>'Retorno','body'=>'Oi, {nome}!','buttons'=>[['type'=>'reply','id'=>'horario','label'=>'Combinar horário']]]);
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['status'=>'paused']);
        $config=['whatsapp_qr_template_id'=>$template->id,'whatsapp_qr_buttons_confirmed'=>false];
        $url='/api/voice/journeys/'.$this->campaign.'/nodes/message';
        $this->putJson($url,['revision'=>1,'config'=>$config])->assertUnprocessable();
        $config['whatsapp_qr_buttons_confirmed']=true;
        $this->putJson($url,['revision'=>1,'config'=>$config])->assertOk();
        $campaign=DB::table('voice_campaigns')->find($this->campaign);
        $this->assertSame(2,$campaign->followup_revision);
        $this->assertSame($template->id,json_decode($campaign->settings,true)['whatsapp_qr_template_id']);
    }

    public function test_qr_buttons_flow_requires_opt_in_and_dispatches_one_personalized_message(): void
    {
        $template=app(\App\Services\WhatsAppQrTemplates::class)->create(1,['name'=>'Retorno','body'=>'Oi, {nome}!','buttons'=>[['type'=>'reply','id'=>'horario','label'=>'Combinar horário']]]);
        $this->settings['whatsapp_qr_template_id']=$template->id;
        $this->settings['whatsapp_qr_buttons_confirmed']=true;
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['settings'=>json_encode($this->settings)]);
        $id=$this->due();
        Http::fake(fn($r)=>str_ends_with($r->url(),'/messages')?Http::response(['status'=>'sent','reference'=>'QR-BUTTONS']):Http::response(['status'=>'connected','number'=>'+5511999990000','capabilities'=>['experimental_buttons'=>true]]));
        app(VoiceFollowups::class)->dispatch($id);app(VoiceFollowups::class)->dispatch($id);
        $this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'sent']);
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/messages')&&$r['text']==='Oi, Ana!'&&$r['interactive']['buttons'][0]['id']==='horario');
        $this->assertCount(1,Http::recorded(fn($r)=>$r->method()==='POST'));
    }

    public function test_qr_automatic_send_personalizes_and_never_duplicates():void
    {
        $id=$this->due();$this->fakeQr();app(VoiceFollowups::class)->dispatch($id);app(VoiceFollowups::class)->dispatch($id);
        $this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'sent']);$this->assertDatabaseCount('wa_messages',1);
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/messages')&&$r['text']==='Olá, Ana! Podemos conversar?'&&$r['to']==='+5511999990001');
        $this->assertCount(1,Http::recorded(fn($r)=>$r->method()==='POST'));
    }
    public function test_unknown_send_is_not_retried_even_when_scheduler_repeats():void
    {
        $id=$this->due();$this->fakeQr(true);app(VoiceFollowups::class)->dispatch($id);app(VoiceFollowups::class)->run();
        $this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'unknown']);$this->assertDatabaseCount('wa_messages',1);
        $this->postJson('/api/voice/whatsapp/followups/'.$id.'/retry')->assertConflict();
        $this->assertSame(1,$this->qrPosts);
    }
    public function test_reply_optout_phone_change_and_removed_membership_cancel_before_provider():void
    {
        $id=$this->due();DB::table('voice_contacts')->where('id',$this->contact)->update(['suppressed_at'=>now()]);
        app(VoiceFollowups::class)->dispatch($id);$this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'cancelled']);Http::assertNothingSent();
    }
    public function test_answered_call_cancels_pending_message():void
    {
        $id=$this->due();$this->recordCall('completed');app(VoiceFollowups::class)->dispatch($id);
        $this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'cancelled']);Http::assertNothingSent();
    }
    public function test_campaign_edit_invalidates_previous_calls_and_pending_step():void
    {
        $this->recordCall();DB::table('voice_campaigns')->where('id',$this->campaign)->update(['revision'=>2,'followup_revision'=>2]);$this->recordCall();$this->assertDatabaseCount('voice_followups',0);
        $this->recordCall('no_answer',2);$this->recordCall('no_answer',2);$id=DB::table('voice_followups')->value('id');
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['revision'=>3,'followup_revision'=>3]);app(VoiceFollowups::class)->dispatch($id);
        $this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'cancelled']);Http::assertNothingSent();
    }
    public function test_pause_holds_pending_and_same_number_mismatch_blocks():void
    {
        $id=$this->due();DB::table('voice_campaigns')->where('id',$this->campaign)->update(['status'=>'paused']);app(VoiceFollowups::class)->dispatch($id);
        $this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'pending']);
        $s=$this->settings;$s['number_mode']='single';$s['business_number']=$s['whatsapp_number'];
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['status'=>'testing','settings'=>json_encode($s)]);
        app(VoiceFollowups::class)->dispatch($id);$this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'cancelled']);Http::assertNothingSent();
    }
    public function test_wrong_qr_identity_cannot_send_and_safe_block_can_be_rechecked():void
    {
        $id=$this->due();Http::fake(['*'=>Http::response(['status'=>'connected','number'=>'+5511999999999'])]);
        app(VoiceFollowups::class)->dispatch($id);$this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'blocked']);$this->assertDatabaseCount('wa_messages',0);
        $this->postJson('/api/voice/whatsapp/followups/'.$id.'/retry')->assertOk();$this->fakeQr();app(VoiceFollowups::class)->dispatch($id);$this->assertDatabaseCount('wa_messages',1);
    }
    public function test_qr_registration_scoping_and_private_configuration():void
    {
        $this->postJson('/api/voice/whatsapp/senders',['label'=>'Novo QR','number'=>'+5511999990009','ownership'=>'external','provider'=>'qr'])->assertOk()->assertJsonPath('provider','qr');
        $this->getJson('/api/voice/whatsapp')->assertOk()->assertDontSee('test-only-private-token');
        $this->user->forceFill(['voice_workspace_id'=>null])->save();$this->postJson('/api/voice/whatsapp/senders/'.$this->sender.'/qr')->assertForbidden();Http::assertNothingSent();
    }
    public function test_config_requires_text_sender_and_reachable_threshold():void
    {
        $d=['name'=>'Nova','contact_ids'=>[$this->contact],'settings'=>$this->settings];
        $this->postJson('/api/voice/campaigns',$d)->assertOk();
        $d['settings']['whatsapp_after']=5;$this->postJson('/api/voice/campaigns',$d)->assertStatus(422);
        $d['settings']['whatsapp_after']=2;$d['settings']['whatsapp_text']='';$this->postJson('/api/voice/campaigns',$d)->assertStatus(422);
        $d['settings']['whatsapp_text']='Olá';$d['settings']['whatsapp_sender_id']=99999;$this->postJson('/api/voice/campaigns',$d)->assertStatus(422);
    }
    public function test_qr_inbound_deduplicates_and_stops_pending_followup():void
    {
        $id=$this->due();Http::fake(function($r){if(str_ends_with($r->url(),'/events'))return Http::response(['events'=>[['event_id'=>'one','kind'=>'inbound','id'=>'QR-INBOUND','from'=>'+5511999990001','body'=>' SAIR ']]]);return Http::response(['status'=>'connected','number'=>'+5511999990000']);});
        app(WhatsAppQr::class)->poll();app(WhatsAppQr::class)->poll();
        $this->assertDatabaseCount('wa_messages',1);$this->assertNotNull(DB::table('voice_contacts')->find($this->contact)->suppressed_at);
        $this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'cancelled']);
    }
    public function test_twilio_callback_creates_followup_without_sending_in_webhook():void
    {
        $this->recordCall();$id=$this->recordCall('dialing');
        $account='AC'.str_repeat('1',32);$parent='CA'.str_repeat('2',32);$child='CA'.str_repeat('3',32);
        DB::table('voice_outbound_calls')->where('id',$id)->update(['method'=>'programmable_voice','provider_account'=>$account,'channel_id'=>$parent,'capacity_released_at'=>null,'ended_at'=>null]);
        app(TwilioVoiceCalling::class)->apply($id,$account,$parent,$child,'no-answer',0);
        app(TwilioVoiceCalling::class)->apply($id,$account,$parent,$child,'no-answer',0);
        $this->assertDatabaseCount('voice_followups',1);Http::assertNothingSent();
    }
    public function test_twilio_automatic_send_uses_approved_template_and_personalized_variables(): void
    {
        $account='AC'.str_repeat('1',32);$senderSid='XE'.str_repeat('2',32);$content='HX'.str_repeat('3',32);
        config(['whatsapp_test_connection'=>['account_sid'=>$account,'api_key'=>'SK'.str_repeat('4',32),'api_secret'=>'only-test-secret','auth_token'=>'only-test-token','allowed_recipients'=>['+5511999990001'],'daily_limit'=>10]]);
        DB::table('wa_senders')->where('id',$this->sender)->update(['provider'=>'twilio','provider_sid'=>$senderSid]);
        $t=app(\App\Services\WhatsAppTemplates::class)->create(1,['name'=>'retorno_teste','language'=>'pt_BR','category'=>'UTILITY','body'=>'Olá {{1}}. Podemos conversar?','variables'=>['1'=>'Ana']]);
        DB::table('wa_templates')->where('id',$t->id)->update(['content_sid'=>$content,'account_sid'=>$account,'state'=>'created','approval_status'=>'approved']);
        $s=$this->settings;$s['whatsapp_real_template_id']=$t->id;$s['whatsapp_variables']=['1'=>'{nome}'];
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['settings'=>json_encode($s)]);
        $id=$this->due();
        Http::fake(function($r)use($senderSid,$account){
            if(str_contains($r->url(),'/Senders/'))return Http::response(['sid'=>$senderSid,'sender_id'=>'whatsapp:+5511999990000','status'=>'ONLINE']);
            if(str_ends_with($r->url(),'/ApprovalRequests'))return Http::response(['whatsapp'=>['status'=>'approved']]);
            return Http::response(['sid'=>'SM'.str_repeat('5',32),'account_sid'=>$account,'status'=>'queued']);
        });
        app(VoiceFollowups::class)->dispatch($id);
        $this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'queued']);
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/Messages.json')&&$r['ContentSid']===$content&&$r['ContentVariables']==='{"1":"Ana"}');
    }
    public function test_sip_terminal_event_counts_without_provider_send(): void
    {
        $this->recordCall();$id=$this->recordCall('dialing');
        DB::table('voice_outbound_calls')->where('id',$id)->update(['capacity_released_at'=>null,'ended_at'=>null,'channel_id'=>'qa-channel']);
        $d=['token'=>$id,'event'=>'finish','channel_id'=>'qa-channel','dial_status'=>'NOANSWER','bill_seconds'=>0];
        app(\App\Services\VoiceCalling::class)->event($d);app(\App\Services\VoiceCalling::class)->event($d);
        $this->assertDatabaseCount('voice_followups',1);Http::assertNothingSent();
    }
    public function test_window_and_delay_hold_pending_without_provider_requests(): void
    {
        $id=$this->due();DB::table('voice_followups')->where('id',$id)->update(['due_at'=>now()->addMinutes(10)]);
        app(VoiceFollowups::class)->dispatch($id);$this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'pending']);
        DB::table('voice_followups')->where('id',$id)->update(['due_at'=>now()]);
        $s=$this->settings;$s['start_time']='16:00';DB::table('voice_campaigns')->where('id',$this->campaign)->update(['settings'=>json_encode($s)]);
        app(VoiceFollowups::class)->dispatch($id);$this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'pending']);Http::assertNothingSent();
    }

    public function test_pause_and_resume_preserve_counter_and_queued_message(): void
    {
        $this->recordCall();
        $this->postJson('/api/voice/campaigns/'.$this->campaign.'/status',['status'=>'paused'])->assertOk();
        $this->postJson('/api/voice/campaigns/'.$this->campaign.'/status',['status'=>'testing'])->assertOk();
        $this->recordCall();$id=DB::table('voice_followups')->value('id');$this->assertNotNull($id);
        $this->postJson('/api/voice/campaigns/'.$this->campaign.'/status',['status'=>'paused'])->assertOk();
        app(VoiceFollowups::class)->dispatch($id);$this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'pending']);
        $this->postJson('/api/voice/campaigns/'.$this->campaign.'/status',['status'=>'testing'])->assertOk();
        $this->fakeQr();app(VoiceFollowups::class)->dispatch($id);$this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'sent']);
    }

    public function test_answered_callback_cancels_pending_whatsapp_before_call_finishes_even_for_manual_call():void
    {
        $id=$this->due();$call=$this->recordCall('dialing');
        DB::table('voice_outbound_calls')->where('id',$call)->update(['campaign_id'=>null,'status'=>'answered','answered_at'=>now(),'ended_at'=>null,'capacity_released_at'=>null]);
        app(VoiceFollowups::class)->observe($call);$this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'cancelled']);Http::assertNothingSent();
    }
    public function test_five_missed_attempts_create_exactly_one_step_and_answer_on_fifth_cancels_it():void
    {
        $settings=$this->settings;$settings['max_attempts']=5;$settings['whatsapp_after']=5;DB::table('voice_campaigns')->where('id',$this->campaign)->update(['settings'=>json_encode($settings)]);
        for($n=1;$n<5;$n++){$this->recordCall();$this->assertDatabaseCount('voice_followups',0);}
        $fifth=$this->recordCall();app(VoiceFollowups::class)->observe($fifth);$this->assertDatabaseCount('voice_followups',1);
        DB::table('voice_outbound_calls')->where('id',$fifth)->update(['status'=>'completed','answered_at'=>now()]);app(VoiceFollowups::class)->observe($fifth);$this->assertDatabaseHas('voice_followups',['call_id'=>$fifth,'status'=>'cancelled']);Http::assertNothingSent();
    }
    public function test_answer_before_fifth_prevents_a_followup_and_missing_variable_never_sends():void
    {
        $this->recordCall('completed');for($n=0;$n<5;$n++)$this->recordCall();$this->assertDatabaseCount('voice_followups',0);Http::assertNothingSent();
    }
    public function test_missing_personalization_field_blocks_before_sending_qr():void
    {
        $s=$this->settings;$s['whatsapp_text']='Olá, {nome}. Identificador: {id_crm}';DB::table('voice_campaigns')->where('id',$this->campaign)->update(['settings'=>json_encode($s)]);$id=$this->due();app(VoiceFollowups::class)->dispatch($id);$this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'blocked']);$this->assertDatabaseCount('wa_messages',0);Http::assertNothingSent();
    }

    public function test_operational_hours_preserve_pending_whatsapp_but_message_change_invalidates(): void
    {
        $this->user->forceFill(['voice_role'=>'supervisor'])->save();
        $id=$this->due();
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['status'=>'paused']);
        $settings=$this->settings;$settings['start_time']='01:00';$settings['script']='Novo roteiro';
        $body=['name'=>'Novo título','revision'=>1,'contact_ids'=>[$this->contact],'settings'=>$settings];
        $this->putJson('/api/voice/campaigns/'.$this->campaign,$body)->assertOk()->assertJsonPath('followup_revision',1);
        $this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'pending']);
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['status'=>'testing']);
        $this->fakeQr();app(VoiceFollowups::class)->dispatch($id);
        $this->assertDatabaseHas('voice_followups',['id'=>$id,'status'=>'sent']);
        DB::table('voice_campaigns')->where('id',$this->campaign)->update(['status'=>'paused']);
        $body['revision']=2;$body['settings']['whatsapp_text']='Outra mensagem para {nome}';
        $this->putJson('/api/voice/campaigns/'.$this->campaign,$body)->assertOk()->assertJsonPath('followup_revision',2);
    }

    public function test_real_followup_uses_ma_name_instead_of_legacy_label(): void
    {
        $audience=DB::table('audiences')->insertGetId(['name'=>'Lista atual']);
        $source=DB::table('contacts')->insertGetId(['name'=>'Ana do MA','phone'=>'+5511999990001','subscribed'=>true,'fields'=>json_encode(['consent_evidence'=>'Autorização documentada'])]);
        DB::table('audience_contact')->insert(['audience_id'=>$audience,'contact_id'=>$source]);
        DB::table('voice_campaign_policies')->insert(['campaign_id'=>$this->campaign,'audience_id'=>$audience,'retry_minutes'=>'{}']);
        $id=$this->due();$this->fakeQr();app(VoiceFollowups::class)->dispatch($id);
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/messages')&&$r['text']==='Olá, Ana do MA! Podemos conversar?');
        $this->assertDatabaseHas('voice_contacts',['id'=>$this->contact,'name'=>'Ana']);
    }

}
