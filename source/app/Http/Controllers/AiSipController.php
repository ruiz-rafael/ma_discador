<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Services\AiSip;
class AiSipController extends Controller {
 public function incoming(Request $r,AiSip $s,string $id){return $s->incoming($r,$id);}
 public function poll(Request $r,AiSip $s){$s->workerAuth($r);return response()->json(['sessions'=>$s->poll()])->header('Cache-Control','no-store, private');}
 public function event(Request $r,AiSip $s,string $id){$s->workerAuth($r);$d=$r->validate(['type'=>'required|in:ended,transcript,handoff','event_id'=>'required_if:type,transcript|string|max:160','text'=>'required_if:type,transcript|string|max:6000']);return $s->event($id,$d);}
}
