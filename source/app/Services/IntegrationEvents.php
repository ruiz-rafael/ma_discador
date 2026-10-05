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
 public function retry(int $w,int $u,string $id,int $revision):array {
  return DB::transaction(function()use($w,$u,$id,$revision){$d=DB::table('ma_event_deliveries')->where('id',$id)->whereIn('integration_id',DB::table('ma_integrations')->where('workspace_id',$w)->select('id'))->lockForUpdate()->firstOrFail();$i=DB::table('ma_integrations')->where('id',$d->integration_id)->firstOrFail();abort_unless($i->active&&$i->webhook_url&&in_array('events:read',json_decode($i->scopes,true),true),409,'A integração precisa estar ativa e permitir eventos.');abort_unless(!$d->archived_at&&$d->revision===$revision&&in_array($d->status,['dead','retry'],true)&&(!$d->locked_until||$d->locked_until<=now()->toDateTimeString()),409,'O evento mudou ou está em entrega. Atualize a tela.');DB::table('ma_event_deliveries')->where('id',$id)->update(['status'=>'pending','attempts'=>0,'next_at'=>now(),'locked_until'=>null,'lease_token'=>null,'last_error'=>null,'revision'=>$revision+1,'updated_at'=>now()]);app(VoiceLab::class)->audit($w,$u,'integration.delivery_retried',$id);return ['ok'=>true];});
 }
 public function deliver(string $id):void {
  $row=DB::transaction(function()use($id){$d=DB::table('ma_event_deliveries')->where('id',$id)->lockForUpdate()->first();if(!$d||$d->archived_at||!in_array($d->status,['pending','retry','delivering'],true)||$d->next_at>now()->toDateTimeString()||($d->locked_until&&$d->locked_until>now()->toDateTimeString()))return null;
   if($d->lease_token)DB::table('ma_delivery_attempts')->where('id',$d->lease_token)->where('status','running')->update(['status'=>'abandoned','error'=>'lease_expired','finished_at'=>now()]);
   $i=DB::table('ma_integrations')->where('id',$d->integration_id)->where('active',true)->first();$stop=(!$i||!$i->webhook_url||!in_array('events:read',json_decode($i->scopes,true),true))?'cancelled':($d->attempts>=6?'dead':null);
   if($stop){DB::table('ma_event_deliveries')->where('id',$id)->update(['status'=>$stop,'locked_until'=>null,'lease_token'=>null,'last_error'=>$stop==='dead'?'attempts_exhausted':'integration_inactive','revision'=>$d->revision+1,'updated_at'=>now()]);return null;}
   $token=(string)Str::uuid();DB::table('ma_delivery_attempts')->insert(['id'=>$token,'delivery_id'=>$id,'attempt'=>$d->attempts+1,'status'=>'running','started_at'=>now()]);DB::table('ma_event_deliveries')->where('id',$id)->update(['status'=>'delivering','attempts'=>$d->attempts+1,'locked_until'=>now()->addSeconds(60),'lease_token'=>$token,'revision'=>$d->revision+1,'updated_at'=>now()]);return [$d,$i,$token];});if(!$row)return;[$d,$i,$token]=$row;$status=null;$success=false;$error='invalid_destination';
  try{$target=$this->destination($i->webhook_url);$error='transport_error';$event=DB::table('ma_public_events')->where('sequence',$d->event_sequence)->firstOrFail();$body=json_encode(json_decode($event->payload,true),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$stamp=(string)time();$signature=hash_hmac('sha256',$stamp.'.'.$body,Crypt::decryptString($i->webhook_secret));$ip=str_contains($target['ip'],':')?'['.$target['ip'].']':$target['ip'];
   $res=Http::withOptions(['curl'=>[CURLOPT_RESOLVE=>[$target['host'].':443:'.$ip]],'stream'=>true])->withHeaders(['X-MA-Event-Id'=>$event->id,'X-MA-Timestamp'=>$stamp,'X-MA-Signature'=>$signature])->withBody($body,'application/json')->connectTimeout(3)->timeout(8)->withoutRedirecting()->post($i->webhook_url);$status=$res->status();$success=$res->successful();$error=$success?null:'http_error';
  }catch(\Throwable){}
  DB::transaction(function()use($id,$d,$token,$status,$success,$error){$current=DB::table('ma_event_deliveries')->where('id',$id)->lockForUpdate()->first();if(!$current||$current->lease_token!==$token||$current->status!=='delivering')return;DB::table('ma_delivery_attempts')->where('id',$token)->update(['status'=>$success?'delivered':'failed','http_status'=>$status,'error'=>$error,'finished_at'=>now()]);DB::table('ma_event_deliveries')->where('id',$id)->update(['status'=>$success?'delivered':($current->attempts>=6?'dead':'retry'),'http_status'=>$status,'last_error'=>$error,'locked_until'=>null,'lease_token'=>null,'revision'=>$current->revision+1,'next_at'=>now()->addSeconds(min(3600,30*(2**$d->attempts))),'updated_at'=>now()]);});
 }
}
