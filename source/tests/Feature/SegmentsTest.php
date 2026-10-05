<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\{Segments,ListContacts};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Queue};
use Tests\TestCase;
class SegmentsTest extends TestCase {
 use RefreshDatabase;private int $list;private User $user;
 protected function setUp():void {parent::setUp();Http::preventStrayRequests();Queue::fake();$this->user=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin']);$this->actingAs($this->user);$this->list=DB::table('audiences')->insertGetId(['name'=>'Segmento QA']);}
 private function url(string $p=''):string{return '/api/lists/automation/'.$this->list.$p;}
 private function fields():array{return [['key'=>'cidade','label'=>'Cidade','type'=>'text','required'=>false],['key'=>'pontos','label'=>'Pontos','type'=>'number','required'=>false]];}
 private function person(string $name,string $city,int $score=10):int{return DB::table('contacts')->insertGetId(['name'=>$name,'email'=>strtolower($name).'@example.test','phone'=>'+5511999990001','fields'=>json_encode(['cidade'=>$city,'pontos'=>$score]),'subscribed'=>false]);}
 private function rules(array $extra=[]):array{return $extra+['mode'=>'rules','rule_match'=>'all','rules'=>[['field'=>'fields.cidade','operator'=>'eq','value'=>'Santos']],'name'=>'Segmento QA','fields'=>$this->fields(),'revision'=>0];}
 private function configure(array $extra=[]){return $this->putJson($this->url(),$this->rules($extra));}
 public function test_dynamic_membership_enters_exits_and_reenters_without_destroying_contact():void {
  $a=$this->person('Ana','Santos');$b=$this->person('Bia','Campinas');$this->configure()->assertOk();$this->assertDatabaseHas('audience_contact',['audience_id'=>$this->list,'contact_id'=>$a]);$this->assertDatabaseMissing('audience_contact',['audience_id'=>$this->list,'contact_id'=>$b]);
  DB::table('contacts')->where('id',$a)->update(['fields'=>'{"cidade":"Campinas"}']);DB::table('contacts')->where('id',$b)->update(['fields'=>'{"cidade":"SANTOS"}']);app(Segments::class)->refreshAll();$this->assertDatabaseMissing('audience_contact',['audience_id'=>$this->list,'contact_id'=>$a]);$this->assertDatabaseHas('audience_contact',['audience_id'=>$this->list,'contact_id'=>$b]);$this->assertDatabaseCount('contacts',2);
  DB::table('contacts')->where('id',$a)->update(['fields'=>'{"cidade":"Santos"}']);app(Segments::class)->refreshAll();$this->assertDatabaseCount('audience_contact',2);Http::assertNothingSent();Queue::assertNothingPushed();
 }
 public function test_preview_all_any_and_numeric_rules_have_no_side_effects():void {
  $this->person('Ana','Santos',10);$this->person('Bia','Campinas',30);$this->putJson($this->url(),['name'=>'QA','fields'=>$this->fields(),'revision'=>0])->assertOk();$d=$this->rules(['rules'=>[['field'=>'fields.cidade','operator'=>'eq','value'=>'Santos'],['field'=>'fields.pontos','operator'=>'gte','value'=>20]]]);$this->postJson($this->url('/segment-preview'),$d)->assertOk()->assertJsonPath('total',0)->assertJsonPath('saved',false);$d['rule_match']='any';$this->postJson($this->url('/segment-preview'),$d)->assertOk()->assertJsonPath('total',2);$this->assertDatabaseCount('audience_contact',0);
 }
 public function test_manual_selection_and_webhook_cannot_bypass_dynamic_rules_or_exclusions():void {
  $a=$this->person('Ana','Santos');$b=$this->person('Bia','Campinas');$this->configure()->assertOk();$this->postJson($this->url('/existing-contacts'),['contact_ids'=>[$b]])->assertOk()->assertJsonPath('added',0)->assertJsonPath('outside_rules',1);
  $this->deleteJson($this->url('/contacts/'.$a))->assertOk();app(Segments::class)->refreshAll();$this->assertDatabaseCount('audience_contact',0);$this->postJson($this->url('/existing-contacts'),['contact_ids'=>[$a]])->assertOk()->assertJsonPath('removed_preserved',1);app(Segments::class)->refreshAll();$this->assertDatabaseCount('audience_contact',0);
  $this->postJson($this->url('/contacts/'.$a.'/restore'))->assertOk();$this->assertDatabaseCount('audience_contact',1);
 }
 public function test_webhook_updates_rule_attributes_but_preserves_identity_and_consent():void {
  $a=$this->person('Ana','Santos');$this->configure()->assertOk();$h=$this->postJson($this->url('/webhooks'),['name'=>'CRM','active'=>true,'revision'=>0,'mapping'=>[['source'=>'nome','target'=>'name'],['source'=>'email','target'=>'email'],['source'=>'cidade','target'=>'fields.cidade'],['source'=>'autorizado','target'=>'consent'],['source'=>'evidencia','target'=>'consent_evidence']]])->assertOk()->json();
  $payload=['nome'=>'Outro nome','email'=>'ana@example.test','cidade'=>'Campinas','autorizado'=>true,'evidencia'=>'Autorização do exemplo'];$this->postJson('/hooks/lists/'.$h['webhook']['id'],$payload,['Authorization'=>'Bearer '.$h['token'],'Idempotency-Key'=>'event-1'])->assertOk()->assertJsonPath('outside_rules',true);$this->assertDatabaseCount('audience_contact',0);$this->assertDatabaseHas('contacts',['id'=>$a,'name'=>'Ana','subscribed'=>false]);
  $payload['cidade']='Santos';$this->postJson('/hooks/lists/'.$h['webhook']['id'],$payload,['Authorization'=>'Bearer '.$h['token'],'Idempotency-Key'=>'event-2'])->assertOk()->assertJsonPath('added',true);$this->assertDatabaseCount('audience_contact',1);$this->assertDatabaseCount('contacts',1);Http::assertNothingSent();Queue::assertNothingPushed();
 }
 public function test_invalid_rules_foreign_campaigns_and_agent_access_are_rejected():void {
  $this->configure(['rules'=>[]])->assertUnprocessable();$this->configure(['rules'=>[['field'=>'fields.unknown','operator'=>'eq','value'=>'x']]])->assertUnprocessable();$this->configure(['rules'=>[['field'=>'consent','operator'=>'eq','value'=>'true']]])->assertUnprocessable();$this->configure(['rules'=>[['field'=>'cadence_engaged','operator'=>'eq','value'=>false,'campaign_id'=>99999]]])->assertUnprocessable();
  $this->user->forceFill(['voice_role'=>'agent'])->save();$this->postJson($this->url('/segment-preview'),$this->rules())->assertForbidden();$this->configure()->assertForbidden();$this->assertDatabaseCount('ma_list_settings',0);
 }
 public function test_engagement_rule_is_campaign_specific_and_keeps_call_and_reply_history():void {
  $a=$this->person('Ana','Santos');$p=app(\App\Services\VoiceJourneyPreset::class)->create(1,$this->user->id,'+12025550123');$v=app(ListContacts::class)->ingest(1,'voice',$p['list_id'],['name'=>'Ana','phone'=>'+5511999990001','source'=>'QA','consent'=>false,'fields'=>[]])['contact_id'];
  $this->configure(['rules'=>[['field'=>'cadence_engaged','operator'=>'eq','value'=>false,'campaign_id'=>$p['campaign_id']]]])->assertOk();$this->assertDatabaseCount('audience_contact',1);
  $sender=DB::table('wa_senders')->insertGetId(['workspace_id'=>1,'provider'=>'qr','label'=>'QA','number'=>'+5511999990002','ownership'=>'external']);
  DB::table('wa_messages')->insert(['id'=>(string)\Illuminate\Support\Str::uuid(),'workspace_id'=>1,'sender_id'=>$sender,'provider'=>'qr','direction'=>'inbound','idempotency_key'=>'reply-qa','request_hash'=>str_repeat('a',64),'account_sid'=>'QR','from_number'=>'+5511999990001','contact_id'=>$v,'campaign_id'=>$p['campaign_id'],'to_number'=>'+5511999990002','body'=>'Podemos falar agora','status'=>'received']);
  app(Segments::class)->refreshAll();$this->assertDatabaseCount('audience_contact',0);$this->assertDatabaseCount('contacts',1);$this->assertDatabaseCount('wa_messages',1);$this->assertDatabaseCount('voice_outbound_calls',0);Http::assertNothingSent();
 }
 public function test_voice_rule_departure_is_reversible_but_manual_removal_is_durable():void {
  $p=app(\App\Services\VoiceJourneyPreset::class)->create(1,$this->user->id,'+12025550123');$list=$p['list_id'];$url='/api/lists/voice/'.$list;$v=app(ListContacts::class)->ingest(1,'voice',$list,['name'=>'Ana','phone'=>'+5511999990001','source'=>'QA','consent'=>false,'fields'=>['cidade'=>'Santos']])['contact_id'];
  $this->putJson($url,$this->rules())->assertOk();DB::table('voice_contacts')->where('id',$v)->update(['fields'=>'{"cidade":"Campinas"}']);app(Segments::class)->refreshAll();$this->assertDatabaseHas('voice_list_members',['list_id'=>$list,'contact_id'=>$v,'status'=>'outside_rules']);DB::table('voice_contacts')->where('id',$v)->update(['fields'=>'{"cidade":"Santos"}']);app(Segments::class)->refreshAll();$this->assertDatabaseHas('voice_list_members',['list_id'=>$list,'contact_id'=>$v,'status'=>'active']);$this->deleteJson($url.'/contacts/'.$v)->assertOk();app(Segments::class)->refreshAll();$this->assertDatabaseHas('voice_list_members',['list_id'=>$list,'contact_id'=>$v,'status'=>'removed']);
 }
 public function test_existing_manual_segments_stay_unchanged_when_refreshing():void {
  $a=$this->person('Ana','Campinas');DB::table('audience_contact')->insert(['audience_id'=>$this->list,'contact_id'=>$a]);$before=DB::table('audience_contact')->get()->toJson();app(Segments::class)->refreshAll();$this->assertSame($before,DB::table('audience_contact')->get()->toJson());$this->getJson($this->url())->assertOk()->assertJsonPath('list.mode','manual')->assertJsonPath('members.total',1);
 }
 public function test_answered_call_removes_only_the_segment_for_its_campaign():void {
  $this->person('Ana','Santos');$p=app(\App\Services\VoiceJourneyPreset::class)->create(1,$this->user->id,'+12025550123');$other=DB::table('voice_campaigns')->insertGetId(['workspace_id'=>1,'name'=>'Outra','status'=>'paused','settings'=>'{}']);$v=app(ListContacts::class)->ingest(1,'voice',$p['list_id'],['name'=>'Ana','phone'=>'+5511999990001','source'=>'QA','consent'=>false,'fields'=>[]])['contact_id'];
  $this->configure(['rules'=>[['field'=>'cadence_engaged','operator'=>'eq','value'=>false,'campaign_id'=>$p['campaign_id']]]])->assertOk();
  $id=(string)\Illuminate\Support\Str::uuid();DB::table('voice_outbound_calls')->insert(['id'=>$id,'workspace_id'=>1,'user_id'=>$this->user->id,'contact_id'=>$v,'campaign_id'=>$other,'idempotency_key'=>(string)\Illuminate\Support\Str::uuid(),'request_hash'=>'qa','grant_hash'=>'qa','configuration_hash'=>'qa','destination'=>'+5511999990001','caller_id'=>'+12025550123','status'=>'completed','max_seconds'=>30,'ring_seconds'=>10,'consent_evidence'=>'QA','grant_expires_at'=>now(),'deadline_at'=>now(),'answered_at'=>now(),'capacity_released_at'=>now()]);
  app(Segments::class)->refreshAll();$this->assertDatabaseCount('audience_contact',1);DB::table('voice_outbound_calls')->where('id',$id)->update(['campaign_id'=>$p['campaign_id']]);app(Segments::class)->refreshAll();$this->assertDatabaseCount('audience_contact',0);$this->assertDatabaseCount('voice_outbound_calls',1);$this->assertDatabaseCount('contacts',1);Http::assertNothingSent();Queue::assertNothingPushed();
 }

}
