<?php
namespace App\Services\Portal;
use Illuminate\Support\Facades\{DB,Crypt,Http};

/** Read-only, cross-provider inventory for platform operators. No credentials in results. */
class NumberInventory {
 public function catalog():array {
  $numbers=[];$errors=[];$provider=app(NumberProvider::class);
  if($provider->config())try{
   foreach($provider->inventory() as $n){
    $reason=empty($n['capabilities']['voice'])?'Sem capacidade de voz':((!empty($n['trunk_sid'])||!empty($n['voice_application_sid'])||!empty($n['voice_url'])||!empty($n['status_callback']))?'Rota de voz já configurada':null);
    $numbers['twilio:'.$n['phone_number']]=['number'=>$n['phone_number'],'provider'=>'twilio','operator'=>'Twilio','available'=>$reason===null,'reason'=>$reason];
   }
  }catch(\Throwable){$errors[]='Não foi possível atualizar os números da Twilio. Tente atualizar a lista.';}
  $complete=true;
  foreach(DB::table('portal_tenants')->whereIn('status',['active','suspended'])->whereNotNull('url')->get() as $t){
   try{
    foreach($this->tenantNumbers($t) as $n){
     $numbers[$n['provider'].':'.$n['number']]=['number'=>$n['number'],'provider'=>$n['provider'],'operator'=>$n['provider']==='twilio'?'Twilio':'Fale Vono','available'=>false,'reason'=>'Vinculado a '.$t->name];
    }
   }catch(\Throwable){$complete=false;$errors[]='Não foi possível conferir os vínculos de '.$t->name.'. Atualize a lista antes de vincular.';}
  }
  foreach(DB::table('portal_number_requests as r')->join('portal_tenants as t','t.id','=','r.tenant_id')->whereNotNull('r.number')->get(['r.number','r.status','t.name']) as $r){
   $key='twilio:'.$r->number;
   $numbers[$key]=['number'=>$r->number,'provider'=>'twilio','operator'=>'Twilio','available'=>false,'reason'=>($r->status==='available'?'Vinculado a ':'Reservado para ').$r->name];
  }
  if(!$complete)foreach($numbers as &$n){if($n['available']){$n['available']=false;$n['reason']='Conferência de vínculos pendente';}}unset($n);
  $entries=collect($numbers)->sortBy(fn($n)=>($n['available']?'0':'1').$n['operator'].$n['number'])->values()->all();
  return ['numbers'=>$entries,'errors'=>$errors,'available'=>count(array_filter($entries,fn($n)=>$n['available']))];
 }
 /** Fetch only this tenant, after the controller has checked membership. */
 public function tenantNumbers(object $t):array {
  abort_unless($t->status==='active' || $t->status==='suspended',409);
  abort_unless(str_starts_with($t->url??'','https://')&&$t->secret,503);
  $path='discai-internal/number-inventory';$stamp=(string)time();$body=json_encode(['tenant'=>$t->slug],JSON_UNESCAPED_SLASHES);
  $r=Http::acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(5)->withHeaders(['X-Discai-Time'=>$stamp,'X-Discai-Signature'=>hash_hmac('sha256',$stamp.'.'.$path.'.'.$body,Crypt::decryptString($t->secret))])->withBody($body,'application/json')->post(rtrim($t->url,'/').'/'.$path);
  abort_unless($r->successful()&&$r->json('tenant')===$t->slug&&is_array($r->json('numbers')),502);
  $numbers=[];
  foreach($r->json('numbers') as $n){
   abort_unless(in_array($n['provider']??null,['twilio','fale_vono'],true)&&preg_match('/^\+[1-9][0-9]{7,14}$/D',$n['number']??''),502);
   $numbers[]=['number'=>$n['number'],'provider'=>$n['provider'],'operator'=>$n['provider']==='twilio'?'Twilio':'Fale Vono'];
  }
  return $numbers;
 }
 public static function display(string $number):string {
  if(preg_match('/^\+55(\d{2})(\d{4,5})(\d{4})$/D',$number,$m))return '+55 ('.$m[1].') '.$m[2].'-'.$m[3];
  return $number;
 }
}
