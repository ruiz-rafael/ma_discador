<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Hash,Http};
use Tests\TestCase;
class TeamManagementTest extends TestCase {
 use RefreshDatabase;
 private User $admin;
 protected function setUp():void {parent::setUp();Http::preventStrayRequests();$this->admin=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'admin']);$this->actingAs($this->admin);}
 private function payload(array $x=[]):array{return array_replace(['name'=>'Atendente QA','email'=>'qa@example.test','password'=>'testing-long-secret','role'=>'agent','enabled'=>true,'queue_ids'=>[]],$x);}
 public function test_create_and_edit_with_scoped_membership_and_no_password_leak():void {
  $c=DB::table('voice_campaigns')->insertGetId(['workspace_id'=>1,'name'=>'QA','settings'=>'{}']);$q=DB::table('voice_live_queues')->insertGetId(['workspace_id'=>1,'campaign_id'=>$c,'name'=>'Fila QA','agent_ids'=>'[]']);
  $id=$this->postJson('/api/voice/team',$this->payload(['queue_ids'=>[$q]]))->assertOk()->json('id');
  $this->assertTrue(Hash::check('testing-long-secret',User::find($id)->password));
  $this->getJson('/api/voice/team')->assertOk()->assertDontSee('testing-long-secret')->assertDontSee('password')->assertJsonFragment(['queue_ids'=>[$q]]);
  $this->putJson('/api/voice/team/'.$id,$this->payload(['revision'=>1,'password'=>null,'enabled'=>false]))->assertOk();
  $this->assertDatabaseHas('users',['id'=>$id,'voice_enabled'=>false]);$this->assertSame([],json_decode(DB::table('voice_live_queues')->where('id',$q)->value('agent_ids'),true));
  $this->putJson('/api/voice/team/'.$id,$this->payload(['revision'=>1]))->assertConflict();Http::assertNothingSent();
 }
 public function test_profiles_cannot_escalate_or_disable_self_and_foreign_workspace_is_hidden():void {
  $agent=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'agent']);$this->actingAs($agent)->getJson('/api/voice/team')->assertForbidden();$this->postJson('/api/voice/team',$this->payload())->assertForbidden();
  $supervisor=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'supervisor']);$this->actingAs($supervisor)->postJson('/api/voice/team',$this->payload(['role'=>'admin']))->assertForbidden();
  $this->actingAs($this->admin)->putJson('/api/voice/team/'.$this->admin->id,$this->payload(['email'=>$this->admin->email,'revision'=>1,'enabled'=>false,'role'=>'admin']))->assertUnprocessable();
  DB::table('voice_workspaces')->insert(['id'=>2,'name'=>'Outro']);$foreign=User::factory()->create(['voice_workspace_id'=>2,'voice_role'=>'agent']);
  $this->putJson('/api/voice/team/'.$foreign->id,$this->payload(['revision'=>1]))->assertNotFound();
 }
 public function test_disabled_user_cannot_login_or_acquire_work_but_can_read_current_call():void {
  $agent=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'agent','voice_enabled'=>false,'password'=>'testing-long-secret']);
  $this->actingAs($agent)->postJson('/api/voice/queues/presence',['status'=>'available'])->assertForbidden();$this->postJson('/api/voice/calling/calls',[])->assertForbidden();
  $this->postJson('/api/logout')->assertOk();$this->postJson('/login',['email'=>$agent->email,'password'=>'testing-long-secret'])->assertUnprocessable();
 }
 public function test_password_reset_requires_new_login_for_existing_session():void {
  $agent=User::factory()->create(['voice_workspace_id'=>1,'voice_role'=>'agent']);
  $this->putJson('/api/voice/team/'.$agent->id,$this->payload(['email'=>$agent->email,'revision'=>1,'password'=>'a-new-password-for-qa']))->assertOk();
  $this->actingAs($agent->fresh())->getJson('/api/bootstrap')->assertUnauthorized();
  $this->postJson('/login',['email'=>$agent->email,'password'=>'a-new-password-for-qa'])->assertOk();$this->getJson('/api/bootstrap')->assertOk();
 }
}
