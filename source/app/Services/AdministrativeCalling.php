<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
/** Uses the existing telephony engine, capacity and consent checks; never a campaign. */
class AdministrativeCalling {
 public function catalog():array {
  $cfg=app(VoiceCallingConfig::class);$result=[];
  foreach(app(QueueChannels::class)->catalog(1)['voice_numbers'] as $n){
   $s=$cfg->status($n['method'],$n['number']);
   $result[]=['number'=>$n['number'],'method'=>$n['method'],'provider'=>$n['provider']??'twilio','ready'=>$s['ready'],
    'reason'=>$s['ready']?null:'A conexão ou os limites de telefonia precisam ser habilitados.',
    'destinations'=>$s['allowed_recipients'],'max_seconds'=>$s['max_seconds']];
  }
  return $result;
 }
 public function origin(string $method,string $number):void {
  $n=collect($this->catalog())->first(fn($n)=>$n['number']===$number&&$n['method']===$method);
  abort_unless($n&&$n['ready'],422,'Número de saída não autorizado ou indisponível. Atualize a lista.');
  app(QueueChannels::class)->origin((object)['workspace_id'=>1,'voice_number'=>$number],app(VoiceCallingConfig::class)->connection($method,$number)??[]);
 }
 public function reserve(int $user,int $operator,array $d):array {
  $this->origin($d['method'],$d['origin']);$phone=VoiceLab::phone($d['destination']);
  $contact=DB::table('voice_contacts')->where('workspace_id',1)->where('phone',$phone)->first();
  abort_unless($contact&&$contact->consent&&$contact->consent_evidence&&!$contact->suppressed_at,422,'O destino precisa ser um contato de teste cadastrado e autorizado na conexão escolhida.');
  return app(VoiceCalling::class)->reserve(1,$user,['method'=>$d['method'],'contact_id'=>$contact->id,'idempotency_key'=>$d['request_id'],
   'consent_evidence'=>$d['consent_evidence'],'consent_confirmed'=>true,'_platform_test'=>['operator'=>$operator,'origin'=>$d['origin']]]);
 }
 public function call(int $operator,string $request):?object {
  $c=DB::table('voice_outbound_calls')->where('workspace_id',1)->where('idempotency_key',$request)->first();
  if($c)abort_unless((json_decode($c->context_snapshot??'{}',true)['platform_operator']??null)===$operator,403);
  return $c;
 }
 public function state(int $operator,string $request):array {
  app(VoiceCalling::class)->expire();$c=$this->call($operator,$request);
  if(!$c)return ['found'=>false];
  return ['found'=>true,'id'=>$c->id,'status'=>$c->status,'destination'=>$c->destination,'caller_id'=>$c->caller_id,'ended'=>(bool)$c->capacity_released_at,
   'dial_status'=>$c->dial_status,'cause'=>$c->cause,'bill_seconds'=>$c->bill_seconds,'answered_at'=>$c->answered_at,'created_at'=>$c->created_at];
 }
}
