<?php
namespace App\Services;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Call while holding voice_runtime lock. Does not change presence or queue selection. */
class AgentWrapup {
 public function start(?object $q,?int $u,string $source,?string $ended):void {
  if(!$q||!$u||!$q->wrapup_enabled||!$q->wrapup_seconds||!$ended)return;
  $until=CarbonImmutable::parse($ended)->addSeconds($q->wrapup_seconds);
  if(!$until->isFuture())return;
  $p=DB::table('voice_agent_presence')->where('workspace_id',$q->workspace_id)->where('user_id',$u)->first();
  if(!$p||$p->wrapup_source===$source||($p->available_after&&CarbonImmutable::parse($p->available_after)->gte($until)))return;
  DB::table('voice_agent_presence')->where('user_id',$u)->update(['available_after'=>$until,'wrapup_queue_id'=>$q->id,'wrapup_source'=>$source,'wrapup_token'=>(string)Str::uuid(),'wrapup_allow_early'=>$q->wrapup_allow_early,'updated_at'=>now()]);
 }
 public function state(int $w,int $u):?array {
  $p=DB::table('voice_agent_presence')->where('workspace_id',$w)->where('user_id',$u)->first();
  if(!$p?->available_after||!CarbonImmutable::parse($p->available_after)->isFuture())return null;
  return ['until'=>CarbonImmutable::parse($p->available_after)->toIso8601String(),'remaining_seconds'=>(int)ceil(now()->diffInSeconds(CarbonImmutable::parse($p->available_after))),'queue_name'=>DB::table('voice_live_queues')->where('workspace_id',$w)->where('id',$p->wrapup_queue_id)->value('name'),'allow_early'=>(bool)$p->wrapup_allow_early,'token'=>$p->wrapup_token];
 }
 public function finish(int $w,int $u,string $session,string $token):void {DB::transaction(function()use($w,$u,$session,$token){
  DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();
  $p=DB::table('voice_agent_presence')->where('workspace_id',$w)->where('user_id',$u)->first();
  abort_unless($p&&$p->session_id===$session,409,'Use a conexão que controla seu atendimento.');
  abort_unless($p->wrapup_token===$token,409,'O pós-atendimento mudou. Atualize antes de encerrar.');
  if(!$p->available_after||!CarbonImmutable::parse($p->available_after)->isFuture())return;
  abort_unless($p->wrapup_allow_early,403,'Esta fila exige cumprir todo o tempo de pós-atendimento.');
  abort_if(app(VoiceAgentCapacity::class)->busy($w,$u),409,'Conclua a chamada e salve a tabulação antes de encerrar o pós-atendimento.');
  DB::table('voice_agent_presence')->where('user_id',$u)->update(['available_after'=>now(),'updated_at'=>now()]);
  app(VoiceLab::class)->audit($w,$u,'agent.wrapup_ended',$p->wrapup_queue_id);
 });}
}
