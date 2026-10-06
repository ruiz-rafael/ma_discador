<?php
namespace App\Http\Controllers;
use App\Services\VoiceRecordings;
use Illuminate\Http\Request;
class VoiceRecordingController extends Controller {
 public function queue(Request $r,int $id){$u=$r->user();abort_unless($u->voice_enabled&&in_array($u->voice_role,['admin','supervisor'],true),403);$db=\Illuminate\Support\Facades\DB::class;$db::table('voice_live_queues')->where('workspace_id',$u->voice_workspace_id)->where('id',$id)->firstOrFail();return response()->json($db::table('voice_recordings')->where('workspace_id',$u->voice_workspace_id)->where(function($q)use($db,$id){foreach(['inbound','outbound']as $kind)$q->orWhere(fn($q)=>$q->where('kind',$kind)->whereIn('call_id',$db::table('voice_'.$kind.'_calls')->where('queue_id',$id)->select('id')));})->orderByDesc('created_at')->limit(50)->get(['id','kind','call_id','status','duration','expires_at','created_at']))->header('Cache-Control','no-store, private');}
 public function state(Request $r,string $kind,string $id){return response()->json(app(VoiceRecordings::class)->state($r->user(),$kind,$id))->header('Cache-Control','no-store, private');}
 public function control(Request $r,string $sid){$d=$r->validate(['status'=>'required|in:paused,in-progress']);app(VoiceRecordings::class)->control($r->user(),$sid,$d['status']);return response()->noContent();}
 public function audio(Request $r,string $sid){return response(app(VoiceRecordings::class)->audio($r->user(),$sid))->header('Content-Type','audio/mpeg')->header('Cache-Control','no-store, private')->header('Content-Disposition','inline; filename="gravacao.mp3"');}
 public function callback(Request $r,string $kind,string $id){app(TwilioVoiceWebhookController::class)->verify($r,'/recordings/'.$kind.'/'.$id);$d=$r->validate(['AccountSid'=>'required|string','CallSid'=>['required','regex:/^CA[a-fA-F0-9]{32}$/D'],'RecordingSid'=>['required','regex:/^RE[a-fA-F0-9]{32}$/D'],'RecordingStatus'=>'required|in:in-progress,completed,absent','RecordingDuration'=>'sometimes|nullable|integer|between:0,86400']);app(VoiceRecordings::class)->callback($kind,$id,$d);return response()->noContent();}
}
