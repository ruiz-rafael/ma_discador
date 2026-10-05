<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Http,Queue};
use Tests\TestCase;

class ListManagerTest extends TestCase
{
    use RefreshDatabase;
    private User $user;
    private int $list;
    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests(); Queue::fake();
        $this->user=User::factory()->create(); $this->user->forceFill(['voice_workspace_id'=>1,'voice_role'=>'admin'])->save(); $this->actingAs($this->user);
        $this->list=DB::table('audiences')->insertGetId(['name'=>'Clientes QA','created_at'=>now(),'updated_at'=>now()]);
    }
    private function url(string $suffix=''): string { return '/api/lists/automation/'.$this->list.$suffix; }
    private function contact(array $extra=[]): array { return array_replace(['name'=>'Ana Souza','email'=>'ana@example.test','phone'=>'+5511999990001','source'=>'Formulário QA','consent'=>false,'consent_evidence'=>null,'fields'=>[]],$extra); }
    private function fields(): array { return [['key'=>'empresa','label'=>'Empresa','type'=>'text','required'=>true],['key'=>'pontos','label'=>'Pontos','type'=>'number','required'=>false]]; }
    private function hook(array $extra=[]): array
    {
        return $this->postJson($this->url('/webhooks'),array_replace(['name'=>'CRM QA','active'=>true,'revision'=>0,'mapping'=>[['source'=>'lead.nome','target'=>'name'],['source'=>'lead.email','target'=>'email'],['source'=>'lead.telefone','target'=>'phone']]],$extra))->assertOk()->json();
    }
    private function deliver(array $hook, array $payload, string $key='evento-1', ?string $token=null)
    {
        return $this->postJson('/hooks/lists/'.$hook['webhook']['id'],$payload,['Authorization'=>'Bearer '.($token??$hook['token']),'Idempotency-Key'=>$key]);
    }
    public function test_catalog_manual_input_and_existing_contact_preservation(): void
    {
        $this->postJson('/api/lists',['name'=>'Voz QA','kind'=>'voice'])->assertOk();
        $this->getJson('/api/lists')->assertOk()->assertJsonCount(2,'lists')->assertJsonPath('can_manage',true);
        $first=$this->postJson($this->url('/contacts'),['data'=>$this->contact()])->assertOk()->assertJsonPath('created',true)->json();
        $this->postJson($this->url('/contacts'),['data'=>$this->contact(['name'=>'Outro nome','phone'=>'(11) 99999-0001','consent'=>true,'consent_evidence'=>'Nova autorização informada'])])->assertOk()->assertJsonPath('preserved',true)->assertJsonPath('added',false);
        $this->assertDatabaseCount('contacts',1);$this->assertDatabaseHas('contacts',['id'=>$first['contact_id'],'name'=>'Ana Souza','subscribed'=>false]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('members.total',1);
        Http::assertNothingSent(); Queue::assertNothingPushed();
    }
    public function test_custom_field_validation_mapping_and_revision_protection(): void
    {
        $this->putJson($this->url(),['name'=>'Clientes revisados','revision'=>0,'fields'=>$this->fields()])->assertOk()->assertJsonPath('revision',1);
        $this->postJson($this->url('/contacts'),['data'=>$this->contact()])->assertUnprocessable();
        $this->postJson($this->url('/contacts'),['data'=>$this->contact(['fields'=>['empresa'=>'Zyrex','pontos'=>'12.5']])])->assertOk();
        $this->getJson($this->url())->assertJsonPath('members.data.0.fields.empresa','Zyrex')->assertJsonPath('members.data.0.fields.pontos',12.5);
        $this->putJson($this->url(),['name'=>'Antigo','revision'=>0,'fields'=>[]])->assertConflict();
        $this->postJson($this->url('/contacts'),['data'=>$this->contact(['fields'=>['empresa'=>'Zyrex','pontos'=>'abc']])])->assertUnprocessable();
    }
    public function test_removed_contact_is_not_reenrolled_by_ingestion_and_requires_explicit_restore(): void
    {
        $id=$this->postJson($this->url('/contacts'),['data'=>$this->contact()])->json('contact_id');
        $this->deleteJson($this->url('/contacts/'.$id))->assertOk();
        $this->postJson($this->url('/contacts'),['data'=>$this->contact()])->assertOk()->assertJsonPath('membership_removed',true)->assertJsonPath('added',false);
        $this->assertDatabaseCount('audience_contact',0);
        $this->postJson($this->url('/contacts/'.$id.'/restore'))->assertOk();$this->assertDatabaseCount('audience_contact',1);
        $this->assertDatabaseHas('contacts',['id'=>$id,'subscribed'=>false]);
    }
    public function test_conflicting_email_and_phone_do_not_merge_or_overwrite_contacts(): void
    {
        $this->postJson($this->url('/contacts'),['data'=>$this->contact()])->assertOk();
        $this->postJson($this->url('/contacts'),['data'=>$this->contact(['name'=>'João','email'=>'joao@example.test','phone'=>'+5511999990002'])])->assertOk();
        $this->postJson($this->url('/contacts'),['data'=>$this->contact(['phone'=>'+5511999990002'])])->assertUnprocessable();
        $this->assertDatabaseCount('contacts',2);
    }
    public function test_csv_preview_maps_custom_columns_and_commit_is_idempotent(): void
    {
        $this->putJson($this->url(),['name'=>'Clientes','revision'=>0,'fields'=>$this->fields()])->assertOk();
        $csv="Pessoa;Fone;Organizacao;Pontos\nAna;+5511999990001;Zyrex;10\nRepetida;+5511999990001;Zyrex;3\nErrada;=FORMULA;Empresa;5\n";
        $mapping=[['source'=>'Pessoa','target'=>'name'],['source'=>'Fone','target'=>'phone'],['source'=>'Organizacao','target'=>'fields.empresa'],['source'=>'Pontos','target'=>'fields.pontos']];
        $this->post($this->url('/csv/inspect'),['file'=>UploadedFile::fake()->createWithContent('contatos.csv',$csv),'delimiter'=>';'],['Accept'=>'application/json'])->assertOk()->assertJsonCount(4,'headers');
        $b=$this->post($this->url('/csv/preview'),['file'=>UploadedFile::fake()->createWithContent('contatos.csv',$csv),'delimiter'=>';','mapping'=>json_encode($mapping),'consent'=>'0','source'=>'CSV QA'],['Accept'=>'application/json'])->assertOk()->assertJsonPath('summary.valid',1)->assertJsonPath('summary.invalid',2)->json();
        $this->assertDatabaseCount('contacts',0);
        $this->postJson($this->url('/imports/'.$b['id'].'/commit'))->assertOk()->assertJsonPath('summary.created',1)->assertJsonPath('summary.added',1);
        $this->postJson($this->url('/imports/'.$b['id'].'/commit'))->assertOk();$this->assertDatabaseCount('contacts',1);
        $this->assertSame('Zyrex',json_decode(DB::table('contacts')->value('fields'),true)['empresa']);Queue::assertNothingPushed();Http::assertNothingSent();
    }
    public function test_csv_rejects_duplicate_headers_and_stale_preview_schema(): void
    {
        $this->post($this->url('/csv/inspect'),['file'=>UploadedFile::fake()->createWithContent('contatos.csv',"nome;nome\nAna;B\n"),'delimiter'=>';'],['Accept'=>'application/json'])->assertUnprocessable();
        $b=$this->post($this->url('/csv/preview'),['file'=>UploadedFile::fake()->createWithContent('contatos.csv',"nome;email\nAna;ana@example.test\n"),'delimiter'=>';','mapping'=>json_encode([['source'=>'nome','target'=>'name'],['source'=>'email','target'=>'email']]),'consent'=>'0','source'=>'CSV'],['Accept'=>'application/json'])->assertOk()->json();
        $this->putJson($this->url(),['name'=>'Nome novo','revision'=>0,'fields'=>[]])->assertOk();
        $this->postJson($this->url('/imports/'.$b['id'].'/commit'))->assertConflict();$this->assertDatabaseCount('contacts',0);
    }
    public function test_webhook_auth_preview_idempotency_and_secret_non_disclosure(): void
    {
        $h=$this->hook();$this->assertNotEmpty($h['token']);$this->assertNotSame($h['token'],DB::table('ma_list_webhooks')->value('token_hash'));
        $mapping=$h['webhook']['mapping'];$payload=['lead'=>['nome'=>'Ana','email'=>'ana@example.test','telefone'=>'+5511999990001']];
        $this->postJson($this->url('/webhook-preview'),['mapping'=>$mapping,'payload'=>$payload])->assertOk()->assertJsonPath('saved',false)->assertJsonPath('contact.name','Ana');
        $this->assertDatabaseCount('contacts',0);
        $this->deliver($h,$payload,'evento-1','invalido')->assertUnauthorized();
        $this->deliver($h,$payload)->assertOk()->assertJsonPath('created',true)->assertJsonPath('duplicate_event',false);
        $this->deliver($h,$payload)->assertOk()->assertJsonPath('duplicate_event',true);
        $payload['lead']['nome']='Mudou';$this->deliver($h,$payload)->assertConflict();
        $this->getJson($this->url())->assertOk()->assertJsonMissing(['token'=>$h['token']])->assertJsonMissing(['token_hash'=>hash('sha256',$h['token'])]);
        $this->assertDatabaseCount('contacts',1);$this->assertDatabaseCount('ma_list_webhook_receipts',1);Http::assertNothingSent();Queue::assertNothingPushed();
    }
    public function test_webhook_rotation_disable_invalid_paths_and_required_custom_fields(): void
    {
        $h=$this->hook();$mapping=$h['webhook']['mapping'];$url=$this->url('/webhooks/'.$h['webhook']['id']);
        $rotated=$this->putJson($url,['name'=>'CRM','revision'=>1,'active'=>true,'mapping'=>$mapping,'rotate'=>true])->assertOk()->json();
        $payload=['lead'=>['nome'=>'Ana','email'=>'ana@example.test']];$this->deliver($h,$payload)->assertUnauthorized();
        $this->deliver($rotated,$payload)->assertOk();
        $this->putJson($url,['name'=>'CRM','revision'=>2,'active'=>false,'mapping'=>$mapping])->assertOk();$this->deliver($rotated,$payload,'novo')->assertGone();
        $mapping[0]['source']='lead.*.nome';$this->postJson($this->url('/webhooks'),['name'=>'Inválido','active'=>true,'revision'=>0,'mapping'=>$mapping])->assertUnprocessable();
        $this->putJson($this->url(),['name'=>'Clientes','revision'=>0,'fields'=>$this->fields()])->assertOk();
        $this->postJson($this->url('/webhook-preview'),['mapping'=>[['source'=>'nome','target'=>'name'],['source'=>'email','target'=>'email']],'payload'=>['nome'=>'Ana','email'=>'ana@example.test']])->assertUnprocessable();
    }
    public function test_webhook_custom_fields_survive_receive_and_active_mapping_prevents_field_removal(): void
    {
        $this->putJson($this->url(),['name'=>'Clientes','revision'=>0,'fields'=>$this->fields()])->assertOk();
        $h=$this->hook(['mapping'=>[['source'=>'nome','target'=>'name'],['source'=>'email','target'=>'email'],['source'=>'empresa','target'=>'fields.empresa']]]);
        $this->deliver($h,['nome'=>'Ana','email'=>'ana@example.test','empresa'=>'Zyrex'])->assertOk();
        $this->putJson($this->url(),['name'=>'Clientes','revision'=>1,'fields'=>[]])->assertUnprocessable();
        $this->getJson($this->url())->assertJsonPath('members.data.0.fields.empresa','Zyrex');
    }
    public function test_voice_intake_preserves_suppression_and_links_campaign_without_calls(): void
    {
        $preset=app(\App\Services\VoiceJourneyPreset::class)->create(1,$this->user->id,'+12025550123');$url='/api/lists/voice/'.$preset['list_id'];
        $r=$this->postJson($url.'/contacts',['data'=>$this->contact()])->assertOk()->json();
        DB::table('voice_contacts')->where('id',$r['contact_id'])->update(['suppressed_at'=>now(),'consent'=>false]);
        $this->postJson($url.'/contacts',['data'=>$this->contact(['consent'=>true,'consent_evidence'=>'Nova declaração'])])->assertOk()->assertJsonPath('preserved',true);
        $this->assertDatabaseHas('voice_members',['campaign_id'=>$preset['campaign_id'],'contact_id'=>$r['contact_id']]);
        $this->assertDatabaseHas('voice_contacts',['id'=>$r['contact_id'],'consent'=>false]);
        $this->deleteJson($url.'/contacts/'.$r['contact_id'])->assertOk();$this->postJson($url.'/contacts',['data'=>$this->contact()])->assertJsonPath('membership_removed',true);
        $this->assertDatabaseCount('voice_outbound_calls',0);$this->assertDatabaseCount('wa_messages',0);Http::assertNothingSent();Queue::assertNothingPushed();
    }
    public function test_agents_and_other_workspaces_cannot_access_lists(): void
    {
        $this->user->forceFill(['voice_role'=>'agent'])->save();$this->getJson('/api/lists')->assertForbidden();
        $this->postJson($this->url('/contacts'),['data'=>$this->contact()])->assertForbidden();
        $this->postJson($this->url('/webhooks'),[])->assertForbidden();
        DB::table('voice_workspaces')->insert(['id'=>2,'name'=>'Outro']);$this->user->forceFill(['voice_role'=>'admin','voice_workspace_id'=>2])->save();
        $this->getJson('/api/lists')->assertForbidden();$this->getJson($this->url())->assertForbidden();
    }
    public function test_select_existing_contacts_preserves_identity_consent_and_exclusions(): void
    {
        $a=$this->postJson($this->url('/contacts'),['data'=>$this->contact()])->json('contact_id');
        $b=$this->postJson($this->url('/contacts'),['data'=>$this->contact(['name'=>'Bia','email'=>'bia@example.test','phone'=>'+5511999990002'])])->json('contact_id');
        $this->deleteJson($this->url('/contacts/'.$b))->assertOk();
        $c=DB::table('contacts')->insertGetId(['name'=>'Carlos','email'=>'carlos@example.test','subscribed'=>false,'fields'=>'{"empresa":"Preservada"}','created_at'=>now(),'updated_at'=>now()]);
        $before=DB::table('contacts')->orderBy('id')->get()->toJson();
        $this->getJson($this->url('/available-contacts'))->assertOk()->assertJsonPath('contacts.total',3)->assertJsonPath('contacts.data.0.membership','active')->assertJsonPath('contacts.data.1.membership','removed')->assertJsonPath('contacts.data.2.membership',null);
        $this->getJson($this->url('/available-contacts?search=carlos@example.test'))->assertOk()->assertJsonPath('contacts.total',1);
        $this->postJson($this->url('/existing-contacts'),['contact_ids'=>[$a,$b,$c]])->assertOk()->assertJsonPath('added',1)->assertJsonPath('removed_preserved',1);
        $this->postJson($this->url('/existing-contacts'),['contact_ids'=>[$c]])->assertOk()->assertJsonPath('added',0);
        $this->assertSame($before,DB::table('contacts')->orderBy('id')->get()->toJson());$this->assertDatabaseCount('audience_contact',2);
        Http::assertNothingSent();Queue::assertNothingPushed();
    }
    public function test_selection_batch_is_atomic_and_rejects_duplicates_and_unauthorized_users(): void
    {
        $c=DB::table('contacts')->insertGetId(['name'=>'Ana','email'=>'ana@example.test','subscribed'=>false,'created_at'=>now(),'updated_at'=>now()]);
        foreach ([[$c,999999],[$c,$c],[]] as $ids) $this->postJson($this->url('/existing-contacts'),['contact_ids'=>$ids])->assertUnprocessable();
        $this->assertDatabaseCount('audience_contact',0);
        $this->getJson('/api/lists/automation/99999/available-contacts')->assertNotFound();
        $this->user->forceFill(['voice_role'=>'agent'])->save();$this->getJson($this->url('/available-contacts'))->assertForbidden();$this->postJson($this->url('/existing-contacts'),['contact_ids'=>[$c]])->assertForbidden();
        DB::table('voice_workspaces')->insert(['id'=>2,'name'=>'Other']);$this->user->forceFill(['voice_role'=>'admin','voice_workspace_id'=>2])->save();$this->postJson($this->url('/existing-contacts'),['contact_ids'=>[$c]])->assertForbidden();
    }
    public function test_existing_voice_selection_enforces_workspace_and_preserves_optout_and_campaign_history(): void
    {
        $preset=app(\App\Services\VoiceJourneyPreset::class)->create(1,$this->user->id,'+12025550123');$url='/api/lists/voice/'.$preset['list_id'];
        $c=$this->postJson($url.'/contacts',['data'=>$this->contact()])->json('contact_id');DB::table('voice_contacts')->where('id',$c)->update(['suppressed_at'=>now(),'replied_at'=>now()]);
        $this->deleteJson($url.'/contacts/'.$c)->assertOk();
        $this->postJson($url.'/existing-contacts',['contact_ids'=>[$c]])->assertOk()->assertJsonPath('removed_preserved',1)->assertJsonPath('added',0);
        DB::table('voice_workspaces')->insert(['id'=>2,'name'=>'Other']);$foreign=DB::table('voice_contacts')->insertGetId(['workspace_id'=>2,'name'=>'Foreign','phone'=>'+12025550124','original_phone'=>'+12025550124','source'=>'QA','consent'=>false,'fields'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        $this->getJson($url.'/available-contacts')->assertOk()->assertJsonPath('contacts.total',1);
        $this->postJson($url.'/existing-contacts',['contact_ids'=>[$foreign]])->assertUnprocessable();
        $this->assertNotNull(DB::table('voice_contacts')->where('id',$c)->value('suppressed_at'));$this->assertNotNull(DB::table('voice_contacts')->where('id',$c)->value('replied_at'));$this->assertDatabaseCount('voice_outbound_calls',0);$this->assertDatabaseCount('wa_messages',0);
        Http::assertNothingSent();Queue::assertNothingPushed();
    }
    public function test_webhook_configuration_requires_identity_and_required_fields_and_preview_never_imports(): void
    {
        $this->postJson($this->url('/webhooks'),['name'=>'Invalid','active'=>true,'revision'=>0,'mapping'=>[['source'=>'nome','target'=>'name']]])->assertUnprocessable();
        $this->putJson($this->url(),['name'=>'Clientes','revision'=>0,'fields'=>$this->fields()])->assertOk();
        $this->postJson($this->url('/webhooks'),['name'=>'Missing field','active'=>true,'revision'=>0,'mapping'=>[['source'=>'nome','target'=>'name'],['source'=>'email','target'=>'email']]])->assertUnprocessable();
        $mapping=[['source'=>'cliente.nome','target'=>'name'],['source'=>'cliente.email','target'=>'email'],['source'=>'cliente.empresa','target'=>'fields.empresa']];
        $payload=['cliente'=>['nome'=>'Ana','email'=>'ana@example.test','empresa'=>'Empresa QA']];
        $this->postJson($this->url('/webhook-preview'),['mapping'=>$mapping,'payload'=>$payload])->assertOk()->assertJsonPath('saved',false)->assertJsonPath('contact.fields.empresa','Empresa QA');
        $payload['cliente']['email']='invalid';$this->postJson($this->url('/webhook-preview'),['mapping'=>$mapping,'payload'=>$payload])->assertUnprocessable();
        $this->assertDatabaseCount('contacts',0);$this->assertDatabaseCount('ma_list_webhook_receipts',0);$this->assertDatabaseCount('ma_list_webhooks',0);Http::assertNothingSent();Queue::assertNothingPushed();
    }

}
