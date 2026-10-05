<?php
namespace App\Services;
use Illuminate\Support\Facades\{DB,Crypt,Http};
use Illuminate\Support\Str;
class IntegrationEvents {
 public function emit(int $w,string $type,string $subject,string $key,array $data=[]):void {
  DB::transaction(function()use($w,$type,$subject,$key,$data){
  if(DB::table('ma_public_events')->where('workspace_id',$w)->where('dedup_key',$key)->exists())return;
  $id=(string)Str::uuid();$payload=['id'=>$id,'version'=>1,'type'=>$type,'subject_id'=>$subject,'occurred_at'=>now()->toIso8601String(),'data'=>$data];
  $new=DB::table('ma_public_events')->insertOrIgnore(['id'=>$id,'workspace_id'=>$w,'type'=>$type,'dedup_key'=>$key,'payload'=>json_encode($payload),'created_at'=>now()]);if(!$new)return;
  $sequence=DB::table('ma_public_events')->where('id',$id)->value('sequence');
  foreach(DB::table('ma_integrations')->where('workspace_id',$w)->where('active',true)->whereNotNull('webhook_url')->get() as $i){if(!in_array('events:read',json_decode($i->scopes,true),true))continue;DB::table('ma_event_deliveries')->insert(['id'=>(string)Str::uuid(),'integration_id'=>$i->id,'event_sequence'=>$sequence,'status'=>'pending','next_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}
  });
 }
 public function destination(string $url):array {
  $p=parse_url($url);abort_unless(filter_var($url,FILTER_VALIDATE_URL)&&($p['scheme']??'')==='https'&&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['fragment'])&&(!isset($p['port'])||$p['port']===443)&&!empty($p['host']),422,'Use um webhook HTTPS público, sem credenciais e na porta 443.');
  $host=$p['host'];abort_if(filter_var($host,FILTER_VALIDATE_IP)||!preg_match('/^(?:[a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/D',$host),422,'Use um domínio público para o webhook.');
  $records=dns_get_record($host,DNS_A|DNS_AAAA);$ips=[];foreach($records?:[] as $r){$ip=$r['ip']??$r['ipv6']??null;if(!$ip)continue;abort_unless(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)&&!str_starts_with(strtolower($ip),'::ffff:'),422,'O destino resolveu para uma rede não pública.');abort_if(\Symfony\Component\HttpFoundation\IpUtils::checkIp($ip,['100.64.0.0/10','192.0.0.0/24','198.18.0.0/15','224.0.0.0/4','240.0.0.0/4','2001:db8::/32','fc00::/7','fe80::/10','ff00::/8']),422,'Rede não pública.');$ips[]=$ip;}
  abort_unless($ips,422,'O domínio do webhook não resolveu para um IP público.');return ['host'=>$host,'ip'=>$ips[0]];
 }
 public function deliver(string $id):void {
  $row=DB::transaction(function()use($id){$d=DB::table('ma_event_deliveries')->where('id',$id)->lockForUpdate()->first();if(!$d||!in_array($d->status,['pending','retry','delivering'],true)||$d->next_at>now()->toDateTimeString()||($d->locked_until&&$d->locked_until>now()->toDateTimeString()))return null;
   $i=DB::table('ma_integrations')->where('id',$d->integration_id)->where('active',true)->first();if(!$i||!$i->webhook_url){DB::table('ma_event_deliveries')->where('id',$id)->update(['status'=>'cancelled','updated_at'=>now()]);return null;}
   DB::table('ma_event_deliveries')->where('id',$id)->update(['status'=>'delivering','attempts'=>$d->attempts+1,'locked_until'=>now()->addSeconds(60),'updated_at'=>now()]);return [$d,$i];});if(!$row)return;[$d,$i]=$row;$status=null;$success=false;
  try{$target=$this->destination($i->webhook_url);$event=DB::table('ma_public_events')->where('sequence',$d->event_sequence)->firstOrFail();$body=json_encode(json_decode($event->payload,true),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$stamp=(string)time();$signature=hash_hmac('sha256',$stamp.'.'.$body,Crypt::decryptString($i->webhook_secret));$ip=str_contains($target['ip'],':')?'['.$target['ip'].']':$target['ip'];
   $res=Http::withOptions(['curl'=>[CURLOPT_RESOLVE=>[$target['host'].':443:'.$ip]],'stream'=>true])->withHeaders(['X-MA-Event-Id'=>$event->id,'X-MA-Timestamp'=>$stamp,'X-MA-Signature'=>$signature])->withBody($body,'application/json')->connectTimeout(3)->timeout(8)->withoutRedirecting()->post($i->webhook_url);$status=$res->status();$success=$res->successful();
  }catch(\Throwable){}
  DB::table('ma_event_deliveries')->where('id',$id)->update(['status'=>$success?'delivered':($d->attempts+1>=6?'dead':'retry'),'http_status'=>$status,'locked_until'=>null,'next_at'=>now()->addSeconds(min(3600,30*(2**$d->attempts))),'updated_at'=>now()]);
 }
}
