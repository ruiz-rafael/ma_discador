<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\OperationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;
class OperationsHealthTest extends TestCase {
 use RefreshDatabase;
 public function test_limits_are_revision_guarded_pause_does_not_mutate_campaigns_and_costs_are_not_assumed_zero():void{Http::preventStrayRequests();$u=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin']);$this->actingAs($u);$before=DB::table('voice_campaigns')->get()->toJson();$d=['revision'=>0,'paused'=>true,'voice_daily_limit'=>3,'whatsapp_daily_limit'=>4,'cost_alert_usd'=>'2.00'];$this->putJson('/api/voice/health/policy',$d)->assertOk();$this->putJson('/api/voice/health/policy',$d)->assertConflict();$this->assertNotNull(app(OperationPolicy::class)->voiceReason(1));$this->getJson('/api/voice/health')->assertOk()->assertJsonPath('limits.external_concurrency',1)->assertJsonPath('costs.known',[])->assertJsonPath('counts.calls_today',0);$this->assertSame($before,DB::table('voice_campaigns')->get()->toJson());$u->forceFill(['voice_role'=>'agent'])->save();$this->getJson('/api/voice/health')->assertForbidden();$this->putJson('/api/voice/health/policy',$d)->assertForbidden();Http::assertNothingSent();}
}
