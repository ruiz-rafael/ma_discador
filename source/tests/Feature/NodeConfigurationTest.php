<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Models\{User,CrmResource,Contact,Journey,JourneyVersion,Audience};
use App\Services\{NodeCatalog,NodeConfiguration,JourneyExecutionEngine,GraphValidator};
class NodeConfigurationTest extends TestCase {
 use RefreshDatabase;
 private function resource(string $kind,string $id,?string $channel=null,array $extra=[]):CrmResource{return CrmResource::create(array_merge(['kind'=>$kind,'external_id'=>$id,'name'=>'Recurso '.$id,'channel'=>$channel,'origin'=>'preparation','active'=>true,'metadata'=>[]],$extra));}
 private function auth():void{$this->actingAs(User::factory()->create());}
 public function test_every_catalog_node_has_a_configuration_schema():void {foreach(NodeCatalog::all()as $n){$this->assertNotEmpty($n['schema']['description'],$n['key']);$this->assertNotEmpty($n['schema']['fields'],$n['key']);}}
 public function test_catalog_is_authenticated_and_searchable_by_channel_and_id():void{
  $this->getJson('/api/crm/catalog?kind=campaign')->assertUnauthorized();$this->auth();$this->resource('campaign','camp-1','email',['name'=>'Campanha Nutrição']);$this->resource('campaign','camp-2','whatsapp',['name'=>'Nutrição Whats']);
  $this->getJson('/api/crm/catalog?kind=campaign&channel=email&q=nutri')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.external_id','camp-1');
  $this->getJson('/api/crm/catalog?kind=campaign&id=camp-2')->assertOk()->assertJsonPath('data.0.channel','whatsapp');
 }
 public function test_preparation_reference_can_be_created_without_connecting_crm():void{
  $this->auth();$this->postJson('/api/crm/catalog',['kind'=>'segment','external_id'=>'segment-vip','name'=>'Clientes VIP'])->assertSuccessful()->assertJsonPath('origin','preparation');
  $this->postJson('/api/node-config/validate',['key'=>'segment_added','settings'=>['_config_version'=>2,'segment_id'=>'segment-vip']])->assertOk()->assertJsonPath('valid',true)->assertJsonCount(1,'activation_pending');
 }
 public function test_unknown_inactive_and_wrong_channel_references_are_rejected():void{
  $this->auth();$this->postJson('/api/node-config/validate',['key'=>'segment_added','settings'=>['segment_id'=>'missing']])->assertUnprocessable();
  $this->resource('segment','inactive',null,['active'=>false]);$this->postJson('/api/node-config/validate',['key'=>'segment_added','settings'=>['segment_id'=>'inactive']])->assertUnprocessable();
  $this->resource('campaign','wa','whatsapp');$this->postJson('/api/node-config/validate',['key'=>'email_event','settings'=>['campaign_id'=>'wa','event'=>'opened']])->assertUnprocessable();
 }
 public function test_incomplete_draft_saves_but_complete_validation_requires_segment():void{
  $g=['nodes'=>[['id'=>'n','position'=>['x'=>0,'y'=>0],'data'=>['key'=>'segment_added','settings'=>['_config_version'=>2]]]],'edges'=>[]];app(GraphValidator::class)->validate($g);$this->auth();$this->postJson('/api/graph/validate',['graph'=>$g])->assertUnprocessable();
 }
 public function test_templates_are_scoped_to_channel_and_campaign():void{
  $this->auth();$this->resource('campaign','camp-a','email');$this->resource('campaign','camp-b','email');$this->resource('template','tpl-a','email',['metadata'=>['campaign_id'=>'camp-a']]);
  $settings=['_config_version'=>2,'campaign_id'=>'camp-b','template'=>'tpl-a','subject'=>'Olá'];$this->postJson('/api/node-config/validate',['key'=>'send_email','settings'=>$settings])->assertUnprocessable();
  $settings['campaign_id']='camp-a';$this->postJson('/api/node-config/validate',['key'=>'send_email','settings'=>$settings])->assertOk();
 }
 public function test_buttons_and_structured_rules_are_validated():void{
  $config=app(NodeConfiguration::class);$this->assertNotEmpty($config->errors('send_whatsapp',['_config_version'=>2,'buttons'=>['sent','duplicate','duplicate']],false));
  $this->assertNotEmpty($config->errors('multi_branch',['branches'=>[['field'=>'score','operator'=>'bad','value'=>10],['field'=>'score','operator'=>'gt','value'=>5]]],true));
  $this->assertEmpty($config->errors('multi_branch',['branches'=>[['field'=>'score','operator'=>'gt','value'=>10],['field'=>'score','operator'=>'gt','value'=>5]]],true));
 }
 public function test_selected_segment_and_campaign_filter_real_enrollment():void{
  Queue::fake();$c=Contact::create(['name'=>'Teste']);$graph=['nodes'=>[['id'=>'s','data'=>['key'=>'segment_added','settings'=>['segment_id'=>'vip']]],['id'=>'e','data'=>['key'=>'email_event','settings'=>['campaign_id'=>'welcome','event'=>'clicked']]]],'edges'=>[]];$j=Journey::create(['title'=>'Filtro','status'=>'active','published_version'=>1,'graph'=>$graph]);JourneyVersion::create(['journey_id'=>$j->id,'version'=>1,'graph'=>$graph]);$e=app(JourneyExecutionEngine::class);
  $this->assertNull($e->enroll($j,$c,'wrong-segment','segment_added',['segment_id'=>'other']));$this->assertNotNull($e->enroll($j,$c,'right-segment','segment_added',['segment_id'=>'vip']));
  $this->assertNull($e->enroll($j,$c,'wrong-campaign','email_event',['campaign_id'=>'other','event'=>'clicked']));$this->assertNull($e->enroll($j,$c,'wrong-event','email_event',['campaign_id'=>'welcome','event'=>'opened']));$this->assertNotNull($e->enroll($j,$c,'right-campaign','email_event',['campaign_id'=>'welcome','event'=>'clicked']));
 }
 public function test_signed_catalog_sync_updates_identity_and_is_idempotent():void{
  $item=$this->resource('segment','vip');$data=['resources'=>[['kind'=>'segment','external_id'=>'vip','name'=>'VIP atualizado','active'=>true]]];$this->postJson('/crm/catalog/sync',$data)->assertUnauthorized();config(['marketing.catalog_secret'=>'catalog-key']);$stamp=(string)time();$body=json_encode($data);$headers=['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_MA_TIMESTAMP'=>$stamp,'HTTP_X_MA_SIGNATURE'=>hash_hmac('sha256',$stamp.'.'.$body,'catalog-key')];
  $this->call('POST','/crm/catalog/sync',[],[],[],$headers,$body)->assertOk()->assertJsonPath('updated',1);$this->call('POST','/crm/catalog/sync',[],[],[],$headers,$body)->assertOk();$this->assertDatabaseCount('crm_resources',1);$this->assertSame($item->id,CrmResource::first()->id);$this->assertSame('crm',$item->fresh()->origin);
  $this->auth();$this->postJson('/api/crm/catalog',['kind'=>'segment','external_id'=>'vip','name'=>'Sobrescrever'])->assertConflict();
 }
 public function test_catalog_sync_rejects_invalid_batch_without_partial_writes():void{
  config(['marketing.catalog_secret'=>'catalog-key']);$data=['resources'=>[['kind'=>'segment','external_id'=>'valid','name'=>'Válido'],['kind'=>'campaign','external_id'=>'invalid','name'=>'Sem canal']]];$body=json_encode($data);$stamp=(string)time();$this->call('POST','/crm/catalog/sync',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_MA_TIMESTAMP'=>$stamp,'HTTP_X_MA_SIGNATURE'=>hash_hmac('sha256',$stamp.'.'.$body,'catalog-key')],$body)->assertUnprocessable();$this->assertDatabaseCount('crm_resources',0);
 }
 public function test_local_list_defaults_preserve_existing_journeys():void{
  $a=Audience::create(['name'=>'Lista']);$this->assertEmpty(app(NodeConfiguration::class)->errors('add_list',['audience_id'=>$a->id],true));$this->assertEmpty(app(NodeConfiguration::class)->errors('open_trigger',[],true));
 }
 public function test_signed_event_payload_reaches_campaign_filter():void {
  Queue::fake();$c=Contact::create(['name'=>'Contato']);$a=Audience::create(['name'=>'Público']);$c->audiences()->attach($a);
  $g=['nodes'=>[['id'=>'trigger','data'=>['key'=>'email_event','settings'=>['campaign_id'=>'welcome','event'=>'clicked']]]],'edges'=>[]];$j=Journey::create(['title'=>'Campanha','status'=>'active','published_version'=>1,'graph'=>$g]);$j->audiences()->attach($a);JourneyVersion::create(['journey_id'=>$j->id,'version'=>1,'graph'=>$g]);config(['marketing.webhook_secret'=>'event-key']);
  foreach(['other','welcome']as $campaign){$body=json_encode(['event_key'=>'event-'.$campaign,'contact_id'=>$c->id,'type'=>'email_event','attributes'=>['campaign_id'=>$campaign,'event'=>'clicked']]);$stamp=(string)time();$this->call('POST','/events',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_MA_TIMESTAMP'=>$stamp,'HTTP_X_MA_SIGNATURE'=>hash_hmac('sha256',$stamp.'.'.$body,'event-key')],$body)->assertOk();}
  $this->assertDatabaseCount('journey_subscribers',1);$this->assertDatabaseHas('journey_subscribers',['event_key'=>'event-welcome']);
 }
 public function test_required_template_parameters_are_validated():void {
  $this->resource('template','personalized','whatsapp',['metadata'=>['parameters'=>['nome']]]);$config=app(NodeConfiguration::class);$this->assertNotEmpty($config->errors('send_whatsapp',['_config_version'=>2,'template'=>'personalized'],true));$this->assertEmpty($config->errors('send_whatsapp',['_config_version'=>2,'template'=>'personalized','parameters'=>['nome'=>'{{contact.name}}']],true));
 }

 public function test_standard_contact_fields_match_and_update_the_real_attributes():void {
  Queue::fake();$c=Contact::create(['name'=>'Antigo','phone'=>'+5511999990000']);$this->assertTrue(app(\App\Services\NodeHandlers::class)->matches($c,['field'=>'phone','operator'=>'eq','value'=>'+5511999990000']));
  $g=['nodes'=>[['id'=>'start','data'=>['key'=>'open_trigger','settings'=>[]]],['id'=>'update','data'=>['key'=>'update_field','settings'=>['field'=>'name','value'=>'Atualizado']]]],'edges'=>[['source'=>'start','target'=>'update','sourceHandle'=>'success']]];$j=Journey::create(['title'=>'Atualizar','status'=>'active','published_version'=>1,'graph'=>$g]);JourneyVersion::create(['journey_id'=>$j->id,'version'=>1,'graph'=>$g]);$e=app(JourneyExecutionEngine::class);$sub=$e->enroll($j,$c,'update');$e->execute($sub->tokens()->first()->id);$e->execute($sub->tokens()->where('node_uuid','update')->first()->id);$this->assertSame('Atualizado',$c->fresh()->name);
 }

}
