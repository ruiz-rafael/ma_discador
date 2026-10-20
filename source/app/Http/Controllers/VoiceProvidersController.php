<?php
namespace App\Http\Controllers;
use App\Services\{SipTrunks,TwilioVoiceConnection,VoiceCallingConfig};
use App\Services\Portal\TenantBridge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoiceProvidersController extends Controller {
 private function access(Request $r):int {
  abort_unless($r->user()->voice_role==='admin',403);
  if(app(TenantBridge::class)->config())abort_unless($r->session()->get('discai_identity.platform_admin',false),403,'As conexões das operadoras são gerenciadas pela administração DiscAI.');
  return (int)$r->user()->voice_workspace_id;
 }
 public function index(Request $r,SipTrunks $s) {
  $w=$this->access($r);$t=app(TwilioVoiceConnection::class)->read();
  $twilio=$t?array_intersect_key($t,array_flip(['account_sid','api_key','application_sid','caller_id','edge','enabled','managed_by_portal'])):null;
  return response()->json(['sip'=>DB::table('voice_sip_trunks')->where('workspace_id',$w)->orderBy('created_at')->get()->map(fn($row)=>$s->summary($row)),'twilio'=>$twilio,'policy_configured'=>(bool)app(VoiceCallingConfig::class)->read()])->header('Cache-Control','no-store, private');
 }
 public function save(Request $r,SipTrunks $s) {
  $w=$this->access($r);$r->validate(['provider'=>'required|in:twilio,fale_vono']);
  if($r->provider==='fale_vono')return response()->json(['connection'=>$s->summary($s->save($w,$r->user()->id,$r->all()))],200)->header('Cache-Control','no-store, private');
  $svc=app(TwilioVoiceConnection::class);$old=$svc->read();$d=$r->all();
  foreach(['api_secret','auth_token']as $key)if(empty($d[$key])&&$old)$d[$key]=$old[$key];
  if($old&&($d['account_sid']??null)!==$old['account_sid'])abort_unless($r->filled('api_secret')&&$r->filled('auth_token'),422,'Informe os novos segredos ao trocar a conta Twilio.');
  if($old&&($d['api_key']??null)!==$old['api_key'])abort_unless($r->filled('api_secret'),422,'Informe o segredo da nova API Key.');
  $svc->save($d);app(\App\Services\VoiceLab::class)->audit($w,$r->user()->id,'voice_provider.twilio_saved','configuration');
  return response()->json(['saved'=>true])->header('Cache-Control','no-store, private');
 }
}
