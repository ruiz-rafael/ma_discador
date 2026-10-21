<?php
namespace App\Services;

use Illuminate\Support\Facades\{Crypt,DB,Validator};

/** Tenant-local SIP credentials. Only sanitized summaries leave the server. */
class SipTrunks
{
 public function validate(array $d):array {
  return Validator::make($d,[
   'id'=>'required|uuid','provider'=>'required|in:fale_vono','label'=>'required|string|max:100',
   'number'=>['required','regex:/^\+55[1-9][0-9]{9,10}$/D'],
   'host'=>'required|in:vono2.me','username'=>['required','regex:/^[A-Za-z0-9_.-]{3,64}$/D'],
   'password'=>['required','string','min:6','max:128','regex:~^[A-Za-z0-9!@$%^&*()_+={}:.?/-]+$~D'],
   'port'=>'required|integer|in:5060','transport'=>'required|in:udp',
   'dial_format'=>'required|in:national,e164,digits',
   'concurrency'=>'required|integer|between:1,100','cps'=>'required|integer|between:1,10',
   'enabled'=>'required|boolean',
  ])->validate();
 }
 public function save(int $w,int $actor,array $input):object {
  Validator::make($input,['id'=>'required|uuid'])->validate();
  return DB::transaction(function()use($w,$actor,$input){
   DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();
   $old=DB::table('voice_sip_trunks')->where('id',$input['id']??'')->first();
   abort_if($old&&(int)$old->workspace_id!==$w,403);
   if($old&&empty($input['password']))$input['password']=$this->config($old)['password'];
   $d=$this->validate($input);
   abort_if(DB::table('voice_outbound_calls')->where('workspace_id',$w)->whereNull('capacity_released_at')->exists(),409,'Conclua as chamadas e reservas antes de alterar a conexão.');
   abort_if(DB::table('voice_sip_trunks')->where('number',$d['number'])->where('id','!=',$d['id'])->exists(),409,'Este número já possui uma conexão.');
   abort_if(DB::table('voice_managed_numbers')->where('number',$d['number'])->exists()||
    ($d['number']===(app(TwilioVoiceConnection::class)->read()['caller_id']??null))||
    ($d['number']===(app(TwilioTrunk::class)->read()['caller_id']??null)),409,'Este número já pertence a outra conexão.');
   abort_if($old&&$old->number!==$d['number']&&DB::table('voice_live_queues')->where('workspace_id',$w)->where('voice_number',$old->number)->exists(),409,'Retire o número das filas antes de alterá-lo.');
   $values=['workspace_id'=>$w,'provider'=>$d['provider'],'label'=>$d['label'],'number'=>$d['number'],'configuration'=>Crypt::encryptString(json_encode($d,JSON_THROW_ON_ERROR)),'enabled'=>$d['enabled'],'revision'=>($old->revision??0)+1,'applied_hash'=>null,'registration_state'=>'pending','checked_at'=>null,'updated_at'=>now()];
   if($old)DB::table('voice_sip_trunks')->where('id',$d['id'])->update($values);
   else DB::table('voice_sip_trunks')->insert(['id'=>$d['id'],'created_at'=>now()]+$values);
   app(VoiceLab::class)->audit($w,$actor,'sip_trunk.saved',$d['id']);
   return DB::table('voice_sip_trunks')->find($d['id']);
  });
 }
 public function config(object $row):array {return $this->validate(json_decode(Crypt::decryptString($row->configuration),true,flags:JSON_THROW_ON_ERROR));}
 public function endpoint(object $row):string {return 'discai-vono-'.str_replace('-','',$row->id);}
 public function hash(object $row):string {return hash('sha256',json_encode([$this->config($row),app(VoiceCallingConfig::class)->read()],JSON_THROW_ON_ERROR));}
 public function ready(object $row):bool {
  return $row->enabled&&$row->registration_state==='registered'&&$row->checked_at&&
   \Carbon\Carbon::parse($row->checked_at)->gt(now()->subSeconds(150))&&
   is_string($row->applied_hash)&&hash_equals($this->hash($row),$row->applied_hash)&&
   (bool)app(VoiceCallingConfig::class)->read();
 }
 public function summary(object $row):array {
  $c=$this->config($row);unset($c['password']);$fresh=$row->checked_at&&\Carbon\Carbon::parse($row->checked_at)->gt(now()->subSeconds(150));
  return $c+['password_saved'=>true,'ready'=>$this->ready($row),'registration_state'=>$fresh?$row->registration_state:($row->checked_at?'stale':'pending'),'checked_at'=>$row->checked_at,'revision'=>$row->revision];
 }
 public function connection(?string $number=null,?int $w=null):?array {
  $q=DB::table('voice_sip_trunks')->when($w,fn($q)=>$q->where('workspace_id',$w));
  $row=$number?$q->where('number',$number)->first():$q->where('enabled',true)->orderBy('created_at')->first();
  if(!$row)return null;
  return $this->config($row)+['sip_trunk_id'=>$row->id,'account_sid'=>str_replace('-','',$row->id),'caller_id'=>$row->number,'endpoint'=>$this->endpoint($row),'runtime_ready'=>$this->ready($row)];
 }
 public function assertCapacity(array $c,?string $ignore=null):void {
  $q=DB::table('voice_outbound_calls')->where('method','sip_trunk')->where('provider_account',$c['account_sid'])->when($ignore,fn($q)=>$q->where('id','!=',$ignore));
  abort_if(((clone $q)->whereNull('capacity_released_at')->count()+DB::table('voice_inbound_calls')->where('provider','sip_trunk')->where('account_sid','SI'.md5($c['endpoint']))->whereNull('capacity_released_at')->count())>=$c['concurrency'],409,'Todos os canais contratados desta conexão estão ocupados.');
 }
 public function browser():array {
  $p=app(VoiceCallingConfig::class)->read();abort_unless($p,422,'Defina os limites de homologação antes de liberar a conexão.');
  $id='discai-browser-'.substr(hash('sha256',$p['event_secret']),0,16);
  return ['username'=>$id,'password'=>$p['sip_password'],'context'=>$id];
 }
 public function render(object $row):string {
  $c=$this->config($row);$id=$this->endpoint($row);$number=substr($row->number,1);
  return <<<CONF
[$id-auth]
type=auth
auth_type=userpass
username={$c['username']}
password={$c['password']}

[$id-aor]
type=aor
contact=sip:{$c['host']}:{$c['port']}
qualify_frequency=45

[$id]
type=endpoint
transport=discai-vono-udp
context=discai-sip-denied
outbound_auth=$id-auth
aors=$id-aor
from_domain={$c['host']}
from_user={$c['username']}
callerid=$number
send_pai=yes
trust_id_inbound=no
disallow=all
allow=alaw,ulaw
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
allow_transfer=no
allow_subscribe=no

[$id-registration]
type=registration
transport=discai-vono-udp
outbound_auth=$id-auth
server_uri=sip:{$c['host']}:{$c['port']}
client_uri=sip:{$c['username']}@{$c['host']}
contact_user={$c['username']}
retry_interval=60
forbidden_retry_interval=300
expiration=300

CONF;
 }
}
