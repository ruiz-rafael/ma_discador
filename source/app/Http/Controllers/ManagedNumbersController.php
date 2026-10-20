<?php
namespace App\Http\Controllers;
use App\Services\{TwilioVoiceConnection,VoiceCallingConfig};
use App\Services\Portal\TenantBridge;
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Http\Request;
class ManagedNumbersController extends Controller {
 private function authorizeInventory(Request $r):void {
  $b=app(TenantBridge::class)->config();$stamp=$r->header('X-Discai-Time','');
  abort_unless($b&&ctype_digit($stamp)&&abs(time()-(int)$stamp)<=30&&strlen($r->getContent())<=10000,401);
  abort_unless(hash_equals(hash_hmac('sha256',$stamp.'.'.$r->path().'.'.$r->getContent(),$b['secret']),$r->header('X-Discai-Signature','')),401);
  abort_unless($r->input('tenant')===$b['slug'],403);
 }
 public function inventory(Request $r){
  $this->authorizeInventory($r);$numbers=[];
  foreach(DB::table('voice_sip_trunks')->get(['number','provider']) as $row)$numbers[$row->provider.':'.$row->number]=['number'=>$row->number,'provider'=>$row->provider];
  foreach(DB::table('voice_managed_numbers')->pluck('number') as $number)$numbers['twilio:'.$number]=['number'=>$number,'provider'=>'twilio'];
  foreach([app(TwilioVoiceConnection::class)->read(),app(VoiceCallingConfig::class)->trunk()] as $c)if(!empty($c['caller_id']))$numbers['twilio:'.$c['caller_id']]=['number'=>$c['caller_id'],'provider'=>'twilio'];
  return response()->json(['tenant'=>$r->input('tenant'),'numbers'=>array_values($numbers)])->header('Cache-Control','no-store, private');
 }
 public function receive(Request $r){
  $this->authorizeInventory($r);
  $d=$r->validate(['number'=>['required','regex:/^\+[1-9][0-9]{7,14}$/D'],'request_id'=>'required|uuid','provider_sid'=>['required','regex:/^PN[a-fA-F0-9]{32}$/D'],'activate'=>'sometimes|boolean','connection'=>'sometimes|array']);
  return DB::transaction(function()use($d){
   DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();$old=DB::table('voice_managed_numbers')->where('number',$d['number'])->first();
   abort_if($old&&($old->request_id!==$d['request_id']||$old->provider_sid!==$d['provider_sid']),409,'Número já associado a outro pedido.');
   $cfg=app(TwilioVoiceConnection::class)->read();
   if(!empty($d['activate'])){
    abort_unless($old&&$cfg&&($cfg['managed_by_portal']??false),409);
    DB::table('voice_managed_numbers')->where('number',$d['number'])->update(['active'=>true,'updated_at'=>now()]);
    DB::table('voice_origins')->updateOrInsert(['workspace_id'=>1,'account_sid'=>$cfg['account_sid'],'number'=>$d['number']],['provider_sid'=>$d['provider_sid'],'kind'=>'owned','enabled'=>true,'ddd'=>\App\Services\VoiceProviderCatalog::ddd($d['number']),'verified_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
   }else{
    abort_unless(isset($d['connection']),422);$new=app(TwilioVoiceConnection::class)->validate($d['connection']);abort_unless($new['caller_id']===$d['number'],422);
    abort_if($cfg&&(!($cfg['managed_by_portal']??false)||$cfg['account_sid']!==$new['account_sid']||$cfg['application_sid']!==$new['application_sid']),409,'Este ambiente possui uma conexão independente. Ela deve ser revisada pela administração antes da liberação.');
    abort_if(DB::table('voice_outbound_calls')->whereNull('capacity_released_at')->exists()||DB::table('voice_inbound_calls')->whereNull('capacity_released_at')->exists(),409,'Aguarde os atendimentos em andamento.');
    if(!$old)DB::table('voice_managed_numbers')->insert(['number'=>$d['number'],'request_id'=>$d['request_id'],'provider_sid'=>$d['provider_sid'],'active'=>false,'created_at'=>now(),'updated_at'=>now()]);
    if(!$cfg){$new['managed_by_portal']=true;if(app()->environment('testing'))config(['twilio_voice_test'=>$new]);else app(VoiceCallingConfig::class)->privateWrite('programmable.enc',Crypt::encryptString(json_encode($new)));}
   }
   return ['number'=>$d['number']];
  });
 }
}
