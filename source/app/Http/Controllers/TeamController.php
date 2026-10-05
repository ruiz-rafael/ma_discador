<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Services\VoiceLab;
use App\Services\VoiceLiveQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
 private function workspace(Request $r): int {
  abort_unless($r->user()->voice_workspace_id && in_array($r->user()->voice_role,['admin','supervisor'],true),403);
  return (int)$r->user()->voice_workspace_id;
 }
 public function index(Request $r) {
  $w=$this->workspace($r);
  $queues=DB::table('voice_live_queues')->where('workspace_id',$w)->get(['id','name','status','agent_ids','revision','direction','inbound_enabled']);
  foreach($queues as $q)$q->agent_ids=json_decode($q->agent_ids,true);
  $users=DB::table('users as u')->leftJoin('voice_agent_presence as p',fn($j)=>$j->on('p.user_id','=','u.id')->where('p.workspace_id',$w))->where('u.voice_workspace_id',$w)->orderBy('u.name')->get(['u.id','u.name','u.email','u.voice_role','u.voice_enabled','u.voice_revision','p.status','p.last_seen_at','p.available_after']);
  foreach($users as $u){
   $u->voice_enabled=(bool)$u->voice_enabled;
   $u->online=$u->voice_enabled && $u->last_seen_at && \Carbon\CarbonImmutable::parse($u->last_seen_at)->gt(now()->subSeconds(90));
   $u->status=$u->online?($u->status??'offline'):'offline';
   $u->active=$this->active($w,$u->id);
   $u->queue_ids=$queues->filter(fn($q)=>in_array($u->id,$q->agent_ids,true))->pluck('id')->values();
  }
  return response()->json(['users'=>$users,'queues'=>$queues,'role'=>$r->user()->voice_role,'user_id'=>$r->user()->id])->header('Cache-Control','no-store, private');
 }
 private function active(int $w,int $id): bool {
  return app(\App\Services\VoiceAgentCapacity::class)->inboundBusy($w,$id) || DB::table('voice_outbound_calls')->where('workspace_id',$w)->where('user_id',$id)->whereNull('capacity_released_at')->exists()
   || DB::table('voice_live_reservations')->where('workspace_id',$w)->where('user_id',$id)->whereIn('status',VoiceLiveQueue::ACTIVE)->exists()
   || DB::table('voice_queue_assignments')->where('workspace_id',$w)->where('user_id',$id)->where('status','active')->exists();
 }
 public function save(Request $r,?int $id=null) {
  $w=$this->workspace($r);
  if(is_string($r->input('email')))$r->merge(['email'=>strtolower(trim($r->input('email')))]);
  $d=$r->validate(['name'=>'required|string|max:160','email'=>['required','email','max:200',Rule::unique('users')->ignore($id)],'password'=>[$id?'nullable':'required','string','min:12','max:128'],'role'=>'required|in:admin,supervisor,agent','enabled'=>'required|boolean','revision'=>$id?'required|integer|min:1':'nullable|integer','queue_ids'=>'present|array|max:100','queue_ids.*'=>'integer|distinct']);
  return DB::transaction(function()use($r,$w,$d,$id){
   DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();
   $u=$id?User::where('voice_workspace_id',$w)->lockForUpdate()->findOrFail($id):new User;
   abort_if($id && $u->voice_revision!==$d['revision'],409,'O cadastro mudou. Atualize a tela antes de salvar.');
   if($r->user()->voice_role!=='admin')abort_unless($d['role']==='agent' && (!$id || $u->voice_role==='agent'),403,'Somente administradores podem gerir perfis de supervisão e administração.');
   abort_if($id===$r->user()->id && (!$d['enabled'] || $d['role']!==$u->voice_role),422,'Outro administrador deve alterar seu próprio acesso.');
   $queues=DB::table('voice_live_queues')->where('workspace_id',$w)->get();
   abort_unless($queues->whereIn('id',$d['queue_ids'])->count()===count($d['queue_ids']),422,'Selecione filas deste workspace.');
   $oldQueues=$id?$queues->filter(fn($q)=>in_array($id,json_decode($q->agent_ids,true),true))->pluck('id')->all():[];
   $removed=array_diff($oldQueues,$d['queue_ids']);
   if($id && $this->active($w,$id))abort_if($removed || !empty($d['password']) || $u->voice_role!==$d['role'],409,'Conclua o atendimento e a tabulação antes de remover filas, trocar o perfil ou a senha. Você pode desativar novas reservas agora.');
   $u->forceFill(['name'=>$d['name'],'email'=>strtolower($d['email']),'voice_role'=>$d['role'],'voice_workspace_id'=>$w,'voice_enabled'=>$d['enabled'],'voice_revision'=>$id?$u->voice_revision+1:1]);
   if(!empty($d['password'])){$u->password=$d['password'];if($id)$u->voice_auth_version++;}
   $u->save();
   foreach($queues as $q){$ids=json_decode($q->agent_ids,true);$new=array_values(array_filter($ids,fn($v)=>$v!==$u->id));if(in_array($q->id,$d['queue_ids'],true))$new[]=$u->id;sort($new);sort($ids);if($new!==$ids)DB::table('voice_live_queues')->where('id',$q->id)->update(['agent_ids'=>json_encode($new),'revision'=>$q->revision+1,'updated_at'=>now()]);}
   if(!$d['enabled'])DB::table('voice_agent_presence')->where('workspace_id',$w)->where('user_id',$u->id)->update(['status'=>'offline','updated_at'=>now()]);
   // A password reset invalidates Laravel's database sessions when present.
   if($id && !empty($d['password']) && config('session.driver')==='database')DB::table(config('session.table','sessions'))->where('user_id',$id)->delete();
   app(VoiceLab::class)->audit($w,$r->user()->id,$id?'team.updated':'team.created',$u->id,['role'=>$d['role'],'enabled'=>$d['enabled'],'queue_ids'=>$d['queue_ids'],'password_changed'=>!empty($d['password'])]);
   return ['id'=>$u->id,'revision'=>$u->voice_revision];
  });
 }
}
