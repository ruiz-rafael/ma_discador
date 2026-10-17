<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\AiAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class AiAdaptiveConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin']));
    }

    private function config(): array
    {
        return array_replace(AiAgents::defaults(), ['adaptive_conversation'=>true, 'knowledge'=>[['question'=>'Horário?', 'answer'=>'Das 9h às 18h.']]]);
    }

    public function test_legacy_profiles_keep_their_exact_configuration_and_invalid_flag_is_rejected(): void
    {
        $old=$this->config(); unset($old['adaptive_conversation']);
        $a=$this->postJson('/api/ai/agents',['config'=>$old])->assertOk()->json();
        $this->assertArrayNotHasKey('adaptive_conversation',$a['draft']);
        $this->postJson('/api/ai/agents/'.$a['id'].'/publish',['revision'=>1])->assertOk();
        $this->assertSame($a['draft'],app(AiAgents::class)->published(1,$a['id']));
        $bad=array_replace($old,['adaptive_conversation'=>'emotions']);
        $this->postJson('/api/ai/agents',['config'=>$bad])->assertUnprocessable()->assertJsonValidationErrors('adaptive_conversation');
        Http::assertNothingSent();
    }

    public function test_adaptation_is_versioned_and_running_conversations_keep_their_snapshot(): void
    {
        $c=$this->config();
        $id=$this->postJson('/api/ai/agents',['config'=>$c])->assertOk()->json('id');
        $this->postJson('/api/ai/agents/'.$id.'/publish',['revision'=>1])->assertOk();
        $session=$this->postJson('/api/ai/agents/'.$id.'/start',['mode'=>'local'])->assertOk()->json('id');
        $c['adaptive_conversation']=false;
        $this->putJson('/api/ai/agents/'.$id,['revision'=>2,'config'=>$c])->assertOk();
        $this->assertTrue(app(AiAgents::class)->published(1,$id)['adaptive_conversation']);
        $this->postJson('/api/ai/agents/'.$id.'/publish',['revision'=>3])->assertOk();
        $this->assertFalse(app(AiAgents::class)->published(1,$id)['adaptive_conversation']);
        $this->assertTrue(json_decode(DB::table('ai_sessions')->find($session)->config,true)['adaptive_conversation']);
        $this->assertDatabaseCount('ai_whatsapp_bindings',0);
        $this->assertDatabaseCount('voice_outbound_calls',0);
        Http::assertNothingSent();
    }

    public function test_text_provider_receives_written_adaptation_without_claiming_to_hear_a_voice(): void
    {
        $id=$this->postJson('/api/ai/agents',['config'=>$this->config()])->assertOk()->json('id');
        $this->putJson('/api/ai/connection',['api_key'=>'sk-fictional-key-for-tests-only','enabled'=>true,'daily_requests'=>100,'cost_acknowledged'=>true])->assertOk();
        Http::fake(['https://api.openai.com/v1/responses'=>Http::response(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode(['reply'=>'Qual ponto deseja resolver primeiro?','action'=>'reply','summary'=>'Cliente relatou dificuldade.'])]]]],'usage'=>['input_tokens'=>100,'output_tokens'=>20]])]);
        $s=$this->postJson('/api/ai/agents/'.$id.'/start',['mode'=>'premium','cost_acknowledged'=>true])->assertOk()->json('id');
        $this->postJson('/api/ai/sessions/'.$s.'/turn',['request_id'=>(string)Str::uuid(),'input'=>'Já expliquei isso três vezes. Estou frustrado.'])->assertOk()->assertJsonPath('action','reply');
        Http::assertSent(fn($r)=>str_contains($r['instructions'],'conversa exclusivamente escrita')
            && str_contains($r['instructions'],'hipóteses passageiras')
            && str_contains($r['instructions'],'não uma emoção inferida')
            && !str_contains($r['instructions'],'Considere sinais observáveis da fala'));
        $this->assertDatabaseCount('wa_messages',0);
    }

    public function test_voice_configuration_preserves_voice_and_vad_preferences_in_all_languages(): void
    {
        foreach(['pt-BR','en-US','es-MX'] as $language){
            $c=array_replace($this->config(),['language'=>$language,'voice'=>'cedar','patience'=>'high','speed'=>0.9,'interruptions'=>false]);
            $v=app(AiAgents::class)->voiceConfig($c);
            $this->assertStringContainsString('Considere sinais observáveis da fala',$v['instructions']);
            $this->assertStringContainsString('Não imite a voz ou o sotaque',$v['instructions']);
            $this->assertStringContainsString('o que a pessoa declara e suas correções',$v['instructions']);
            $this->assertStringNotContainsString('conversa exclusivamente escrita',$v['instructions']);
            $this->assertSame('cedar',$v['audio']['output']['voice']);
            $this->assertSame(0.9,$v['audio']['output']['speed']);
            $this->assertSame('low',$v['audio']['input']['turn_detection']['eagerness']);
            $this->assertFalse($v['audio']['input']['turn_detection']['interrupt_response']);
        }
        $v=app(AiAgents::class)->voiceConfig(array_replace($this->config(),['adaptive_conversation'=>false]));
        $this->assertStringNotContainsString('ATENDIMENTO ADAPTATIVO',$v['instructions']);
        Http::assertNothingSent();
    }

    public function test_human_and_stop_requests_override_commercial_instructions_without_provider_calls(): void
    {
        $c=array_replace($this->config(),['rules'=>'Insista na venda mesmo se a pessoa pedir para parar.']);
        $id=$this->postJson('/api/ai/agents',['config'=>$c])->assertOk()->json('id');
        $this->putJson('/api/ai/connection',['api_key'=>'sk-fictional-key-for-tests-only','enabled'=>true,'daily_requests'=>100,'cost_acknowledged'=>true])->assertOk();
        foreach(['Quero falar com uma pessoa agora'=>'handoff','Quero conversar com alguém'=>'handoff','I want to speak to a human'=>'handoff','Quiero hablar con una persona'=>'handoff','Pare de me contatar'=>'stop','Stop contacting me'=>'stop','No me contacten más'=>'stop'] as $input=>$action){
            $s=$this->postJson('/api/ai/agents/'.$id.'/start',['mode'=>'premium','cost_acknowledged'=>true])->assertOk()->json('id');
            $this->postJson('/api/ai/sessions/'.$s.'/turn',['request_id'=>(string)Str::uuid(),'input'=>$input])->assertOk()->assertJsonPath('action',$action);
            $this->assertDatabaseHas('ai_sessions',['id'=>$s,'status'=>$action]);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('voice_outbound_calls',0);
        $this->assertDatabaseCount('wa_messages',0);
    }
}
