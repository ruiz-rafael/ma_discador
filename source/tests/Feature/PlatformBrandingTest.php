<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\{PlatformBranding,VoiceJourneyPreset};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;
class PlatformBrandingTest extends TestCase {
 use RefreshDatabase;
 public function test_rebrand_updates_editable_labels_without_changing_routes_permissions_or_identity():void {
  Http::preventStrayRequests();$u=User::factory()->create(['name'=>'Administrador ZYREX','email'=>'admin@zyrex.example.test','voice_workspace_id'=>1,'voice_role'=>'admin']);
  $p=app(VoiceJourneyPreset::class)->create(1,$u->id,'+12025550123');$c=DB::table('voice_campaigns')->find($p['campaign_id']);$settings=json_decode($c->settings,true);
  $settings['whatsapp_text']='Olá! Equipe Zyrex.';$settings['script']='Equipe ZYREX · https://zyrex.example.test · contato@zyrex.example.test';$settings['callback_url']='https://zyrex.example.test/callback';
  DB::table('voice_campaigns')->where('id',$c->id)->update(['name'=>'Zyrex | Cadência','settings'=>json_encode($settings)]);
  DB::table('voice_live_queues')->where('id',$p['queue_id'])->update(['name'=>'Zyrex | Fila']);
  DB::table('wa_templates')->where('id',$p['template_id'])->update(['name'=>'zyrex_teste','body'=>'Equipe Zyrex']);
  $before=DB::table('voice_live_queues')->find($p['queue_id']);app(PlatformBranding::class)->apply();
  $after=DB::table('voice_live_queues')->find($p['queue_id']);$this->assertSame('DiscAI | Fila',$after->name);foreach(['status','agent_ids','mode','strategy','wrapup_seconds']as $key)$this->assertSame($before->$key,$after->$key);
  $this->assertSame($before->revision+1,$after->revision);$this->assertSame('admin@zyrex.example.test',$u->fresh()->email);$this->assertSame('Administrador DiscAI',$u->fresh()->name);
  $updated=json_decode(DB::table('voice_campaigns')->find($c->id)->settings,true);$this->assertSame('Olá! Equipe DiscAI.',$updated['whatsapp_text']);$this->assertSame('Equipe DiscAI · https://zyrex.example.test · contato@zyrex.example.test',$updated['script']);$this->assertSame($settings['callback_url'],$updated['callback_url']);
  $this->assertDatabaseHas('wa_templates',['id'=>$p['template_id'],'name'=>'discai_teste','body'=>'Equipe DiscAI']);
  $this->assertSame([],app(PlatformBranding::class)->apply());Http::assertNothingSent();
 }
 public function test_provider_registered_templates_are_not_silently_rewritten():void {
  Http::preventStrayRequests();$u=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin']);$p=app(VoiceJourneyPreset::class)->create(1,$u->id,'+12025550123');
  DB::table('wa_templates')->where('id',$p['template_id'])->update(['name'=>'zyrex_approved','body'=>'Equipe Zyrex','content_sid'=>'HX'.str_repeat('a',32),'state'=>'published']);
  app(PlatformBranding::class)->apply();$this->assertDatabaseHas('wa_templates',['id'=>$p['template_id'],'name'=>'zyrex_approved','body'=>'Equipe Zyrex']);Http::assertNothingSent();
 }
}
