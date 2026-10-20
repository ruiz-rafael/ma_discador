<?php
namespace App\Services\Portal;
use Illuminate\Support\Facades\{DB,Http,Crypt};
class AdministrativeDialer {
 public function authorize(int $user):void {abort_unless(app(Access::class)->operator($user),403);}
 public function authorizeVoice(int $user):void {
  $this->authorize($user);
  $row=DB::table('portal_test_calls')->where('user_id',$user)->where('method','sip_trunk')->where('state','active')->where('created_at','>',now()->subMinutes(10))->first();
  abort_unless($row,403,'Não há uma chamada SIP administrativa autorizada.');
  $result=$this->action($user,$row->id,'status')['call'];
  abort_unless(($result['found']??false)===true&&!$result['ended']&&in_array($result['status'],['pending','dialing','answered'],true),403,'A autorização de áudio desta tentativa não está disponível.');
 }
 private function tenant(int $id):object {$t=DB::table('portal_tenants')->find($id);abort_unless($t&&$t->status==='active'&&$t->secret&&str_starts_with($t->url??'','https://'),409,'A conexão deste número não está disponível.');return $t;}
 private function request(object $t,int $user,array $data):array {
  $this->authorize($user);$path='discai-internal/calling';$stamp=(string)time();$base=app()->environment('testing')&&config('platform_calling_test_base')?config('platform_calling_test_base'):$t->url;$body=json_encode(['tenant'=>$t->slug,'operator'=>$user]+$data,JSON_UNESCAPED_SLASHES);
  try{$r=Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(($data['action']??'')==='catalog'?5:20)->withHeaders(['X-Discai-Time'=>$stamp,'X-Discai-Signature'=>hash_hmac('sha256',$stamp.'.'.$path.'.'.$body,Crypt::decryptString($t->secret))])->withBody($body,'application/json')->post(rtrim($base,'/').'/'.$path);}
  catch(\Throwable){abort(503,'Não foi possível confirmar o retorno da conexão. Consulte o resultado antes de tentar outra chamada.');}
  if(!$r->successful()){
   $message=in_array($r->status(),[403,409,422,429],true)&&is_string($r->json('message'))?mb_substr($r->json('message'),0,300):'A conexão de telefonia não confirmou a operação.';
   abort(in_array($r->status(),[403,409,422,429],true)?$r->status():503,$message);
  }
  abort_unless($r->json('tenant')===$t->slug,502,'Retorno de conexão inválido.');return $r->json();
 }
 public function catalog(int $user):array {
  $this->authorize($user);$numbers=[];$errors=[];
  foreach(DB::table('portal_tenants')->where('status','active')->whereNotNull('url')->get() as $t){
   try{
    $d=$this->request($this->tenant($t->id),$user,['action'=>'catalog']);
    foreach($d['numbers']??[] as $n){
     if(!in_array($n['method']??null,['sip_trunk','programmable_voice'],true)||!preg_match('/^\+[1-9][0-9]{7,14}$/D',$n['number']??''))continue;
     $token=Crypt::encryptString(json_encode(['tenant'=>$t->id,'user'=>$user,'number'=>$n['number'],'method'=>$n['method'],'until'=>time()+3600]));
     $numbers[]=['route'=>$token,'method'=>$n['method'],'number'=>$n['number'],'operator'=>($n['provider']??'')==='fale_vono'?'Fale Vono':'Twilio','company'=>$t->name,'ready'=>(bool)($n['ready']??false),'reason'=>$n['reason']??null,'destinations'=>$n['destinations']??[]];
    }
   }catch(\Throwable){$errors[]='Não foi possível consultar os números de '.$t->name.'.';}
  }
  return ['numbers'=>$numbers,'errors'=>$errors];
 }
 public function recent(int $user):array {
  $this->authorize($user);return DB::table('portal_test_calls')->where('user_id',$user)->latest('created_at')->limit(20)->get(['id','origin','destination','method','state','result','created_at'])->map(function($c){$c->result=json_decode($c->result??'null',true);return $c;})->all();
 }
 public function create(int $user,array $d):array {
  $this->authorize($user);
  try{$route=json_decode(Crypt::decryptString($d['route']),true);}catch(\Throwable){abort(422,'Selecione novamente o número de saída.');}
  abort_unless(($route['user']??null)===$user&&($route['until']??0)>=time(),422,'A lista de números expirou. Atualize e selecione novamente.');
  $t=$this->tenant($route['tenant']);$phone=\App\Services\VoiceLab::phone($d['destination']);
  $hash=hash('sha256',json_encode([$t->id,$route['method'],$route['number'],$phone,$d['consent_evidence']]));
  $fresh=DB::transaction(function()use($user,$d,$route,$phone,$hash,$t){
   DB::table('users')->where('id',$user)->lockForUpdate()->firstOrFail();
   $old=DB::table('portal_test_calls')->find($d['request_id']);
   if($old){abort_unless((int)$old->user_id===$user&&hash_equals($old->request_hash,$hash),409,'Identificador utilizado por outra solicitação.');return false;}
   abort_if(DB::table('portal_test_calls')->where('user_id',$user)->where('state','!=','ended')->exists(),409,'Confira ou encerre sua chamada anterior antes de iniciar outra.');
   DB::table('portal_test_calls')->insert(['id'=>$d['request_id'],'user_id'=>$user,'tenant_id'=>$t->id,'method'=>$route['method'],'origin'=>$route['number'],'destination'=>$phone,'request_hash'=>$hash,'state'=>'creating','created_at'=>now(),'updated_at'=>now()]);return true;
  });
  if(!$fresh)abort(409,'Esta tentativa já foi registrada. Consulte o resultado; ela não será repetida.');
  app(Access::class)->audit('platform.call_requested',$user,$t->id,['request'=>$d['request_id'],'origin'=>$route['number'],'destination'=>$phone]);
  try{
   $result=$this->request($t,$user,['action'=>'reserve','request_id'=>$d['request_id'],'origin'=>$route['number'],'method'=>$route['method'],'destination'=>$phone,'consent_confirmed'=>true,'consent_evidence'=>$d['consent_evidence']]);
   abort_unless(is_array($result['grant']??null)&&($result['grant']['method']??null)===$route['method']&&is_string($result['grant']['id']??null),502,'A conexão não confirmou a reserva.');
   // The central session cannot authenticate to a tenant's same-origin WebSocket.
   // Use the portal's operator-only proxy; SIP credentials and one-use call token stay tenant-scoped.
   if($route['method']==='sip_trunk')$result['grant']['server']=preg_replace('~^https://~','wss://',rtrim(config('discai.portal_url'),'/')).'/platform-calling/voice/ws';
   DB::table('portal_test_calls')->where('id',$d['request_id'])->update(['state'=>'active','updated_at'=>now()]);
   return ['id'=>$d['request_id'],'grant'=>$result['grant']];
  }catch(\Throwable $e){
   // A timeout is not proof that the remote reservation failed. Recover through status.
   $known=$e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface&&in_array($e->getStatusCode(),[403,409,422,429],true);
   DB::table('portal_test_calls')->where('id',$d['request_id'])->update(['state'=>$known?'ended':'unknown','result'=>json_encode(['status'=>'failed','ended'=>$known,'message'=>$known?$e->getMessage():'Resultado não confirmado. Consulte esta tentativa.']),'updated_at'=>now()]);throw $e;
  }
 }
 public function action(int $user,string $id,string $action):array {
  $this->authorize($user);$row=DB::table('portal_test_calls')->where('user_id',$user)->where('id',$id)->firstOrFail();
  if($row->state==='ended')return ['id'=>$id,'call'=>json_decode($row->result??'{}',true)];
  $d=$this->request($this->tenant($row->tenant_id),$user,['action'=>$action,'request_id'=>$id]);$call=$d['call']??[];
  if(($call['found']??null)===false){
   $ended=\Carbon\CarbonImmutable::parse($row->created_at)->lt(now()->subMinutes(2));
   $call=['found'=>false,'status'=>$ended?'failed':'unknown','ended'=>$ended,'message'=>$ended?'A conexão não registrou esta chamada. Nenhuma discagem foi confirmada.':'Aguardando confirmação da reserva.'];
  }
  abort_unless(is_string($call['status']??null)&&is_bool($call['ended']??null),502,'Resultado de telefonia inválido.');
  DB::table('portal_test_calls')->where('id',$id)->update(['state'=>$call['ended']?'ended':'active','result'=>json_encode($call),'updated_at'=>now()]);
  return ['id'=>$id,'call'=>$call];
 }
}
