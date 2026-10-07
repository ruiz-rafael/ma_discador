<?php
namespace App\Http\Controllers;
use App\Services\SpeechStudio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class SpeechStudioController extends Controller {
 private function workspace(Request $r):int {$u=$r->user();abort_unless($u->voice_enabled&&$u->voice_workspace_id&&in_array($u->voice_role,['admin','supervisor'],true),403);return $u->voice_workspace_id;}
 private function data(Request $r):array {return $r->validate(['name'=>'required|string|max:160','body'=>'required|string|max:1200','engine'=>'sometimes|in:kokoro,chatterbox','voice'=>'required|string|max:40','speed'=>'required|numeric|between:0.8,1.2','revision'=>'sometimes|integer|min:1','values'=>'sometimes|array|max:12','values.*'=>'required|string|max:300']);}
 public function index(Request $r,SpeechStudio $s){$w=$this->workspace($r);return response()->json(['engines'=>$s->catalog(),'service'=>$s->health(),'voices'=>SpeechStudio::VOICES,'conversations'=>DB::table('wa_conversations')->where('workspace_id',$w)->where('assigned_user_id',$r->user()->id)->where('status','open')->where('last_inbound_at','>',now()->subHours(24))->get(['id','phone','sender_id']),'templates'=>DB::table('speech_templates')->where('workspace_id',$w)->orderByDesc('updated_at')->limit(100)->get(),'assets'=>DB::table('speech_assets')->where('workspace_id',$w)->where('expires_at','>',now())->orderByDesc('created_at')->limit(30)->get()->map(fn($a)=>$s->refresh($a))])->header('Cache-Control','no-store, private');}
 public function save(Request $r,SpeechStudio $s,?string $id=null){return response()->json($s->save($this->workspace($r),$this->data($r),$id));}
 public function generate(Request $r,SpeechStudio $s){return response()->json($s->generate($this->workspace($r),$r->user()->id,$this->data($r)),202);}
 public function asset(Request $r,SpeechStudio $s,string $id){return response()->json($s->asset($this->workspace($r),$id))->header('Cache-Control','no-store, private');}
 public function audio(Request $r,SpeechStudio $s,string $id,string $format){return $this->respond($s->file($this->workspace($r),$id,$format),$format);}
 public function delivery(Request $r,SpeechStudio $s,int $workspace,string $id,string $format){return $this->respond($s->file($workspace,$id,$format),$format);}
 private function respond(string $file,string $format){return response()->file($file,['Content-Type'=>['mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg'][$format],'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff','Content-Disposition'=>'inline; filename="mensagem.'.$format.'"']);}
}
