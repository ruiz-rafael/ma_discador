<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\SpeechStudio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,File,URL};
use Illuminate\Support\Str;
use Tests\TestCase;
class SpeechStudioTest extends TestCase {
 use RefreshDatabase;
 private User $admin;private string $root;
 protected function setUp():void {parent::setUp();Http::preventStrayRequests();$this->root=sys_get_temp_dir().'/ma-speech-'.Str::uuid();config(['speech_test'=>['root'=>$this->root,'url'=>'http://speech:8091','token'=>str_repeat('x',64)]]);$this->admin=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin']);$this->actingAs($this->admin);}
 protected function tearDown():void {File::deleteDirectory($this->root);parent::tearDown();}
 private function data(array $more=[]):array{return array_replace(['name'=>'Recado QA','body'=>'Olá, {primeiro_nome}. Aqui é a {empresa}.','voice'=>'pf_dora','speed'=>1,'values'=>['primeiro_nome'=>'Marina','empresa'=>'Exemplo']],$more);}
 private function ready(object $a):void {$p=$this->root.'/1/'.$a->id;File::ensureDirectoryExists($p);file_put_contents($p.'/state.json',json_encode(['status'=>'ready','fingerprint'=>$a->fingerprint,'duration'=>8.5,'generation_seconds'=>3]));foreach(['wav','mp3','ogg']as $f)file_put_contents($p.'/audio.'.$f,'RIFF'.str_repeat('0',100));}
 public function test_templates_are_workspace_scoped_and_use_optimistic_locking():void {
  $id=$this->postJson('/api/voice/speech/templates',$this->data())->assertOk()->json('id');
  $this->putJson('/api/voice/speech/templates/'.$id,$this->data(['revision'=>1,'name'=>'Novo nome']))->assertOk()->assertJsonPath('revision',2);
  $this->putJson('/api/voice/speech/templates/'.$id,$this->data(['revision'=>1]))->assertStatus(409);
  DB::table('speech_templates')->where('id',$id)->update(['workspace_id'=>2]);$this->putJson('/api/voice/speech/templates/'.$id,$this->data(['revision'=>2]))->assertNotFound();Http::assertNothingSent();
 }
 public function test_generation_renders_once_and_deduplicates_without_telephony():void {
  Http::fake(['http://speech:8091/generate'=>Http::response(['status'=>'generating'],202)]);
  $d=$this->data(['values'=>['primeiro_nome'=>'{empresa}','empresa'=>'Exemplo']]);$id=$this->postJson('/api/voice/speech/generate',$d)->assertAccepted()->assertJsonPath('body','Olá, {empresa}. Aqui é a Exemplo.')->json('id');
  $this->postJson('/api/voice/speech/generate',$d)->assertAccepted()->assertJsonPath('id',$id);Http::assertSentCount(1);
  Http::assertSent(fn($r)=>$r->url()==='http://speech:8091/generate'&&$r['workspace_id']===1&&$r['text']==='Olá, {empresa}. Aqui é a Exemplo.');
  $this->assertDatabaseCount('voice_outbound_calls',0);$this->assertDatabaseCount('wa_messages',0);$this->assertDatabaseCount('voice_agent_presence',0);
 }
 public function test_missing_variables_unknown_voice_and_oversized_render_never_reach_worker():void {
  $this->postJson('/api/voice/speech/generate',$this->data(['values'=>['empresa'=>'Exemplo']]))->assertStatus(422);
  $this->postJson('/api/voice/speech/generate',$this->data(['voice'=>'../../etc/passwd']))->assertStatus(422);
  $this->postJson('/api/voice/speech/generate',$this->data(['body'=>'Olá {{nome}}']))->assertStatus(422);
  $this->postJson('/api/voice/speech/generate',$this->data(['body'=>str_repeat('{empresa}',8),'values'=>['empresa'=>str_repeat('a',300)]]))->assertStatus(422);Http::assertNothingSent();
 }
 public function test_private_preview_and_signed_delivery_are_scoped_and_expire():void {
  Http::fake(['http://speech:8091/generate'=>Http::response([],202)]);$a=app(SpeechStudio::class)->generate(1,$this->admin->id,$this->data());
  $this->get('/api/voice/speech/assets/'.$a->id.'/mp3')->assertStatus(409);$this->ready($a);
  $this->getJson('/api/voice/speech/assets/'.$a->id)->assertOk()->assertJsonPath('status','ready');$this->get('/api/voice/speech/assets/'.$a->id.'/wav')->assertOk()->assertHeader('Content-Type','audio/wav');
  $link=app(SpeechStudio::class)->deliveryUrl(1,$a->id,'ogg');$this->get($link)->assertOk();$this->get(str_replace('/1/','/2/',$link))->assertForbidden();
  $this->get('/media/speech/1/'.$a->id.'/ogg')->assertForbidden();$this->travel(61)->minutes();$this->get($link)->assertForbidden();
  DB::table('speech_assets')->where('id',$a->id)->update(['workspace_id'=>2]);$this->getJson('/api/voice/speech/assets/'.$a->id)->assertNotFound();
 }
 public function test_agent_cannot_generate_manage_or_download_admin_audio():void {
  $u=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'agent']);$this->actingAs($u);$this->postJson('/api/voice/speech/generate',$this->data())->assertForbidden();$this->getJson('/api/voice/speech')->assertForbidden();Http::assertNothingSent();
 }
 public function test_worker_busy_and_interrupted_job_have_explicit_results():void {
  Http::fake(['http://speech:8091/generate'=>Http::response([],429)]);$this->postJson('/api/voice/speech/generate',$this->data())->assertAccepted()->assertJsonPath('status','failed');
  Http::fake(['http://speech:8091/generate'=>Http::response([],202)]);$a=app(SpeechStudio::class)->generate(1,$this->admin->id,$this->data());$this->travel(11)->minutes();$this->getJson('/api/voice/speech/assets/'.$a->id)->assertOk()->assertJsonPath('status','failed');
 }
 public function test_retention_removes_audio_and_personalized_text_but_keeps_templates():void {
  Http::fake(['http://speech:8091/generate'=>Http::response([],202)]);$s=app(SpeechStudio::class);$s->save(1,$this->data(),null);$a=$s->generate(1,$this->admin->id,$this->data());$this->ready($a);$this->travel(31)->days();$this->assertSame(1,$s->purge());$this->assertDatabaseHas('speech_assets',['id'=>$a->id,'status'=>'expired','body'=>'']);$this->assertDatabaseCount('speech_templates',1);$this->assertDirectoryDoesNotExist($this->root.'/1/'.$a->id);
 }
}
