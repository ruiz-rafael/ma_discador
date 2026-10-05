<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\{WhatsAppQrTemplates,WhatsAppQr,VoiceFollowups};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppQrTemplatesTest extends TestCase
{
    use RefreshDatabase;
    private User $user;
    private int $contact;
    private int $sender;
    private int $posts=0;
    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests();
        $this->user=User::factory()->create();$this->user->forceFill(['voice_workspace_id'=>1,'voice_role'=>'supervisor'])->save();$this->actingAs($this->user);
        $this->contact=DB::table('voice_contacts')->insertGetId(['workspace_id'=>1,'name'=>'Gustavo Almeida','phone'=>'+5511999990001','original_phone'=>'+5511999990001','source'=>'QA','consent'=>true,'consent_evidence'=>'Autorização de homologação']);
        $this->sender=DB::table('wa_senders')->insertGetId(['workspace_id'=>1,'label'=>'QR QA','number'=>'+5511999990000','ownership'=>'external','provider'=>'qr']);
        config(['whatsapp_qr_test'=>['url'=>'http://whatsapp-qr:3000','token'=>'test-only-private-token','allowed_recipients'=>['+5511999990001'],'daily_limit'=>10]]);
    }
    private function model(): array
    {
        return ['name'=>'Retorno QR','body'=>'Olá, {primeiro_nome}! Qual horário é melhor?', 'buttons'=>[['type'=>'reply','id'=>'horario','label'=>'Combinar horário'],['type'=>'url','id'=>'site','label'=>'Nosso site','url'=>'https://zyrex.ia.br'],['type'=>'reply','id'=>'SAIR','label'=>'Não quero contato']]];
    }
    private function template(): string { return app(WhatsAppQrTemplates::class)->create(1,$this->model())->id; }
    private function payload(?string $template=null): array
    {
        return ['sender_id'=>$this->sender,'contact_id'=>$this->contact,'qr_template_id'=>$template??$this->template(),'experimental_confirmed'=>true,'idempotency_key'=>(string)Str::uuid(),'consent_confirmed'=>true,'consent_evidence'=>'Autorização para homologação'];
    }
    private function fake(bool $capability=true, bool $timeout=false): void
    {
        Http::fake(function($r)use($capability,$timeout){
            if(str_ends_with($r->url(),'/messages')){
                $this->posts++;
                if($timeout)throw new \Illuminate\Http\Client\ConnectionException('timeout');
                return Http::response(['status'=>'sent','reference'=>'QR-REF']);
            }
            return Http::response(['status'=>'connected','number'=>'+5511999990000','capabilities'=>['experimental_buttons'=>$capability],'events'=>[]]);
        });
    }
    public function test_create_and_preview_do_not_contact_provider_or_mutate_campaigns(): void
    {
        $t=$this->postJson('/api/voice/whatsapp/qr/templates',$this->model())->assertSuccessful()->json();
        $this->postJson('/api/voice/whatsapp/qr/preview',['qr_template_id'=>$t['id'],'contact_id'=>$this->contact])->assertOk()->assertJsonPath('body','Olá, Gustavo! Qual horário é melhor?')->assertJsonPath('buttons.0.id','horario');
        $copy=$this->model();$copy['body']='Outra mensagem, {nome}.';
        $this->postJson('/api/voice/whatsapp/qr/templates',$copy)->assertSuccessful();
        $this->assertSame($this->model()['body'],DB::table('wa_qr_templates')->where('id',$t['id'])->value('body'));
        $this->assertDatabaseCount('wa_qr_templates',2);$this->assertDatabaseCount('wa_messages',0);Http::assertNothingSent();
    }
    public function test_invalid_buttons_variables_and_unsafe_urls_are_rejected(): void
    {
        $base=$this->model();$cases=[];
        $x=$base;$x['buttons']=[];$cases[]=$x;
        $x=$base;$x['buttons'][]=$x['buttons'][0];$cases[]=$x;
        $x=$base;$x['buttons'][1]['id']='horario';$cases[]=$x;
        foreach(['javascript:alert(1)','http://example.com','https://user:pass@example.com','https://example.com/{nome}']as$url){$x=$base;$x['buttons'][1]['url']=$url;$cases[]=$x;}
        $x=$base;$x['body']='Oi, {campo_inexistente}';$cases[]=$x;
        $x=$base;$x['buttons'][0]['label']='{nome}';$cases[]=$x;
        foreach($cases as$x)$this->postJson('/api/voice/whatsapp/qr/templates',$x)->assertUnprocessable();
        $this->assertDatabaseCount('wa_qr_templates',0);Http::assertNothingSent();
    }
    public function test_experimental_confirmation_and_connector_capability_are_required(): void
    {
        $d=$this->payload();$d['experimental_confirmed']=false;
        $this->postJson('/api/voice/whatsapp/qr/messages',$d)->assertUnprocessable();Http::assertNothingSent();
        $d['experimental_confirmed']=true;$this->fake(false);
        $this->postJson('/api/voice/whatsapp/qr/messages',$d)->assertUnprocessable();
        $this->assertDatabaseCount('wa_messages',0);Http::assertNotSent(fn($r)=>$r->method()==='POST');
    }
    public function test_send_is_idempotent_and_preserves_personalized_snapshot(): void
    {
        $this->fake();$d=$this->payload();
        $first=$this->postJson('/api/voice/whatsapp/qr/messages',$d)->assertOk()->assertJsonPath('status','sent')->json();
        DB::table('voice_contacts')->where('id',$this->contact)->update(['name'=>'Nome atualizado']);
        $this->postJson('/api/voice/whatsapp/qr/messages',$d)->assertOk()->assertJsonPath('id',$first['id'])->assertJsonPath('body','Olá, Gustavo! Qual horário é melhor?');
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/messages')&&$r['interactive']['mode']==='experimental_buttons'&&$r['interactive']['buttons'][0]['id']==='horario'&&$r['text']==='Olá, Gustavo! Qual horário é melhor?');
        $this->assertSame(1,$this->posts);
        $d['qr_template_id']=$this->template();$this->postJson('/api/voice/whatsapp/qr/messages',$d)->assertConflict();
    }
    public function test_timeout_never_retries_or_falls_back_to_text(): void
    {
        $this->fake(true,true);$d=$this->payload();
        $this->postJson('/api/voice/whatsapp/qr/messages',$d)->assertOk()->assertJsonPath('status','unknown');
        $this->postJson('/api/voice/whatsapp/qr/messages',$d)->assertOk()->assertJsonPath('status','unknown');
        $this->assertDatabaseCount('wa_messages',1);
        $this->assertSame(1,$this->posts);
    }
    public function test_templates_are_scoped_and_assigned_agents_cannot_manage_them(): void
    {
        $id=$this->template();DB::table('voice_workspaces')->insert(['id'=>2,'name'=>'Outro']);DB::table('wa_qr_templates')->where('id',$id)->update(['workspace_id'=>2]);
        $this->postJson('/api/voice/whatsapp/qr/preview',['qr_template_id'=>$id,'contact_id'=>$this->contact])->assertNotFound();
        $this->postJson('/api/voice/whatsapp/qr/messages',$this->payload($id))->assertNotFound();
        $this->user->forceFill(['voice_role'=>'agent'])->save();
        $this->postJson('/api/voice/whatsapp/qr/templates',$this->model())->assertForbidden();Http::assertNothingSent();
    }
    public function test_button_reply_is_correlated_and_stop_suppresses_contact(): void
    {
        $this->fake();$d=$this->payload();$m=$this->postJson('/api/voice/whatsapp/qr/messages',$d)->assertOk()->json();
        Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();
        Http::fake(function($r){
            if(str_ends_with($r->url(),'/events'))return Http::response(['events'=>[['kind'=>'inbound','event_id'=>'e1','id'=>'inbound1','from'=>'+5511999990001','body'=>'Não quero contato','reply'=>['id'=>'SAIR','label'=>'Não quero contato','context_id'=>'QR-REF']]]]);
            return Http::response(['status'=>'connected','number'=>'+5511999990000']);
        });
        app(WhatsAppQr::class)->poll();app(WhatsAppQr::class)->poll();
        $row=DB::table('wa_messages')->where('direction','inbound')->first();
        $this->assertSame($m['id'],json_decode($row->interactive,true)['matched_message_id']);
        $this->assertNotNull(DB::table('voice_contacts')->find($this->contact)->suppressed_at);
        $this->assertSame(1,DB::table('wa_messages')->where('direction','inbound')->count());
    }
    public function test_button_template_change_is_a_rule_change_but_missing_defaults_are_not(): void
    {
        $this->assertFalse(VoiceFollowups::ruleChanged([],['whatsapp_qr_template_id'=>null,'whatsapp_qr_buttons_confirmed'=>false]));
        $this->assertTrue(VoiceFollowups::ruleChanged([],['whatsapp_qr_template_id'=>$this->template(),'whatsapp_qr_buttons_confirmed'=>true]));
    }
}
