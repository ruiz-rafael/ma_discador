<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\{SipTrunks,VoiceCallingConfig};

/** Private host reconciler interface. Never exposed through HTTP. */
class SipRuntime extends Command {
 protected $signature='voice:sip-runtime {action : export or status}';
 protected $description='Synchronize tenant SIP configuration with the private PBX reconciler';
 public function handle():int {
  $svc=app(SipTrunks::class);
  if($this->argument('action')==='export'){
   $policy=app(VoiceCallingConfig::class)->read();$rows=DB::table('voice_sip_trunks')->get();
   $result=['busy'=>DB::table('voice_outbound_calls')->whereNull('capacity_released_at')->exists(),'policy'=>$policy,'browser'=>$policy?$svc->browser():null,'callback'=>\App\Services\Portal\TenantUrls::origin().'/internal/voice/calling/event','trunks'=>[]];
   foreach($rows as $row)$result['trunks'][]=['id'=>$row->id,'revision'=>$row->revision,'enabled'=>(bool)$row->enabled,'hash'=>$svc->hash($row),'endpoint'=>$svc->endpoint($row),'configuration'=>$row->enabled?$svc->render($row):''];
   if($twilio=app(\App\Services\TwilioSipRuntime::class)->export())$result['trunks'][]=$twilio;
   $this->line(json_encode($result,JSON_THROW_ON_ERROR));return 0;
  }
  if($this->argument('action')==='status'){
   $data=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
   foreach($data as $d){
    if(str_starts_with($d['id']??'','TK')){app(\App\Services\TwilioSipRuntime::class)->record($d);continue;}
    if(!in_array($d['state']??null,['registered','rejected','unregistered','disabled','error','no_policy'],true))continue;
    $row=DB::table('voice_sip_trunks')->find($d['id']);if(!$row||!hash_equals($svc->hash($row),$d['hash']))continue;
    DB::table('voice_sip_trunks')->where('id',$row->id)->where('revision',$row->revision)->update(['applied_hash'=>$d['hash'],'registration_state'=>$d['state'],'checked_at'=>now()]);
   }
   return 0;
  }
  return 1;
 }
}
