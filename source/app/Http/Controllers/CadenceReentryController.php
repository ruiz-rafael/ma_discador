<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\CadenceReentry;
class CadenceReentryController extends Controller {
 private function campaign(Request $r,int $id):object {abort_unless(in_array($r->user()->voice_role,['admin','supervisor'],true),403);return DB::table('voice_campaigns')->where('workspace_id',$r->user()->voice_workspace_id)->find($id)??abort(404);}
 public function runs(Request $r,int $id):array {
  $c=$this->campaign($r,$id);$d=$r->validate(['page'=>'sometimes|integer|between:1,100000','contact_id'=>'nullable|integer|min:1']);
  $q=DB::table('voice_cadence_runs as r')->join('voice_contacts as c','c.id','=','r.contact_id')->where('r.campaign_id',$id)->when($d['contact_id']??null,fn($q)=>$q->where('r.contact_id',$d['contact_id']));
  $rows=$q->orderByDesc('r.created_at')->orderByDesc('r.number')->select(['r.id','r.number','r.contact_id','c.name','c.phone','r.status','r.exit_reason','r.trigger','r.entered_at','r.ended_at'])->paginate(20);
  foreach($rows as $row){$row->calls=DB::table('voice_outbound_calls')->where('run_id',$row->id)->whereNotNull('started_at')->count();$row->messages=DB::table('wa_messages')->where('run_id',$row->id)->where('direction','outbound')->count();}
  return ['runs'=>$rows,'configured'=>app(CadenceReentry::class)->policy($c)!==null];
 }
 public function preview(Request $r,int $id):array {$c=$this->campaign($r,$id);$d=$r->validate(['contact_id'=>'required|integer|min:1','event_key'=>'required|string|max:160|regex:/^[A-Za-z0-9_.:\-]+$/D']);return app(CadenceReentry::class)->event($c->workspace_id,$id,$d['contact_id'],$d['event_key'],true);}
 public function details(Request $r,int $id,string $run):array {$this->campaign($r,$id);$row=DB::table('voice_cadence_runs')->where('campaign_id',$id)->find($run);abort_unless($row,404);return ['run'=>$row,'calls'=>DB::table('voice_outbound_calls')->where('run_id',$run)->orderBy('created_at')->get(['id','status','destination','started_at','answered_at','ended_at']),'messages'=>DB::table('wa_messages')->where('run_id',$run)->orderBy('created_at')->get(['id','direction','status','body','button_label','created_at'])];}
}
