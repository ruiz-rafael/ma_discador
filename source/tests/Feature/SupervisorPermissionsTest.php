<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class SupervisorPermissionsTest extends TestCase {
 use RefreshDatabase;
 public function test_supervisor_cannot_change_users_queues_campaigns_or_integrations():void {
  Http::preventStrayRequests();$u=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'supervisor']);$this->actingAs($u);
  foreach(['/api/voice/team','/api/voice/operations/queues','/api/voice/campaigns','/api/voice/whatsapp/senders','/api/contacts','/api/journeys','/api/voice/inbound/routes'] as $path)$this->postJson($path,[])->assertForbidden();
  foreach(['/api/voice/operations/queues/1','/api/voice/team/1','/api/voice/campaigns/1','/api/voice/whatsapp/onboarding/settings'] as $path)$this->putJson($path,[])->assertForbidden();
  $this->getJson('/api/voice/provider/twilio')->assertForbidden();$this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('contacts',[])->assertJsonPath('journeys',[]);
  $this->getJson('/api/voice/conversations')->assertOk()->assertJsonPath('can_manage',true)->assertJsonPath('can_configure',false);$this->postJson('/api/voice/conversations/routes',[])->assertForbidden();
  $this->getJson('/api/voice/supervision')->assertOk();Http::assertNothingSent();
 }
}
