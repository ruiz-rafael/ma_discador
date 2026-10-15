<?php
namespace App\Services\Portal;
use Illuminate\Support\Facades\{DB,Crypt,Http};
use Illuminate\Validation\ValidationException;

class NumberOrders {
 public const DDDS=['11','12','13','14','15','16','17','18','19','21','22','24','27','28','31','32','33','34','35','37','38','41','42','43','44','45','46','47','48','49','51','53','54','55','61','62','63','64','65','66','67','68','69','71','73','74','75','77','79','81','82','83','84','85','86','87','88','89','91','92','93','94','95','96','97','98','99'];
 public const LABELS=['requested'=>'Solicitado','quoted'=>'Opções disponíveis','purchasing'=>'Contratação em andamento','uncertain'=>'Conferência necessária','purchased'=>'Contratado · aguardando liberação','releasing'=>'Configurando para o cliente','available'=>'Disponível','rejected'=>'Não aprovado'];
 public function tenant(int $id):object {return DB::table('portal_tenants')->where('status','active')->where('id',$id)->firstOrFail();}
 public function order(string $id):object {return DB::table('portal_number_requests')->where('id',$id)->firstOrFail();}
 public function request(int $tenant,int $actor,array $d):void {
  $this->tenant($tenant);
  DB::transaction(function()use($tenant,$actor,$d){
   $old=DB::table('portal_number_requests')->find($d['id']);if($old){abort_unless($old->tenant_id===$tenant&&$old->requested_by===$actor,409);return;}
   DB::table('portal_number_requests')->insert(['id'=>$d['id'],'tenant_id'=>$tenant,'requested_by'=>$actor,'ddd'=>$d['ddd'],'purpose'=>$d['purpose'],'notes'=>$d['notes']??null,'status'=>'requested','created_at'=>now(),'updated_at'=>now()]);
   app(Access::class)->audit('number.requested',$actor,$tenant,['request'=>$d['id'],'ddd'=>$d['ddd']]);
  });
 }
 public function quote(string $id,int $actor):void {
  $r=$this->order($id);$this->tenant($r->tenant_id);abort_unless(in_array($r->status,['requested','quoted']),409,'Este pedido já avançou. Atualize a página.');
  $quote=app(NumberProvider::class)->options($r->ddd);$quote['token']=bin2hex(random_bytes(24));
  abort_unless(DB::table('portal_number_requests')->where('id',$id)->whereIn('status',['requested','quoted'])->update(['quote'=>json_encode($quote),'quote_expires_at'=>now()->addMinutes(10),'status'=>'quoted','updated_at'=>now()]),409);
  app(Access::class)->audit('number.quoted',$actor,$r->tenant_id,['request'=>$id,'count'=>count($quote['numbers'])]);
 }
 public function buy(string $id,int $actor,array $d):void {
  $r=$this->order($id);$this->tenant($r->tenant_id);$q=json_decode($r->quote??'{}',true);
  abort_unless($r->status==='quoted'&&$r->quote_expires_at>now()&&hash_equals($q['token']??'', $d['quote_token'])&&in_array($d['number'],array_column($q['numbers']??[],'number'),true),409,'A cotação expirou ou já foi utilizada. Consulte novamente.');
  abort_unless(($q['account_sid']??'')===app(NumberProvider::class)->config()['account_sid'],409,'A conta de contratação mudou. Consulte novamente.');
  // Reconfirm inventory and the provider price immediately before the paid operation.
  $fresh=app(NumberProvider::class)->options($r->ddd);
  abort_unless($fresh['monthly']===$q['monthly']&&$fresh['currency']===$q['currency']&&in_array($d['number'],array_column($fresh['numbers'],'number'),true),409,'Preço ou disponibilidade alterados. Consulte as opções novamente.');
  DB::transaction(function()use($id,$actor,$d,$q,$r){
   $old=DB::table('portal_number_requests')->where('id',$id)->lockForUpdate()->first();
   abort_unless($old->status==='quoted'&&hash_equals(json_decode($old->quote,true)['token'],$d['quote_token']),409,'A contratação já foi iniciada.');
   abort_if(DB::table('portal_number_requests')->where('number',$d['number'])->where('id','!=',$id)->exists(),409,'Número já vinculado a outro pedido.');
   DB::table('portal_number_requests')->where('id',$id)->update(['status'=>'purchasing','number'=>$d['number'],'account_sid'=>$q['account_sid'],'bundle_sid'=>$d['bundle_sid'],'address_sid'=>$d['address_sid']??null,'handled_by'=>$actor,'message'=>null,'updated_at'=>now()]);
   app(Access::class)->audit('number.purchase_authorized',$actor,$r->tenant_id,['request'=>$id,'number'=>$d['number'],'monthly'=>$q['monthly'],'currency'=>$q['currency'],'bundle'=>$d['bundle_sid']]);
  });
  try{$n=app(NumberProvider::class)->purchase($this->order($id));
   if(($n['phone_number']??'')!==$d['number']||($n['account_sid']??'')!==$q['account_sid']||!preg_match('/^PN[a-fA-F0-9]{32}$/D',$n['sid']??''))throw new \RuntimeException('provider_unknown');
   $this->purchased($id,$n['sid'],$actor);
  }catch(ValidationException $e){DB::table('portal_number_requests')->where('id',$id)->update(['status'=>'requested','number'=>null,'quote'=>null,'message'=>'Contratação recusada pela operadora. Revise os dados antes de tentar novamente.','updated_at'=>now()]);throw $e;
  }catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){DB::table('portal_number_requests')->where('id',$id)->update(['status'=>'requested','number'=>null,'quote'=>null,'message'=>'Revise o cadastro regulatório.','updated_at'=>now()]);throw $e;
  }catch(\Throwable){DB::table('portal_number_requests')->where('id',$id)->update(['status'=>'uncertain','message'=>'A resposta da contratação não foi confirmada. Confira o resultado antes de qualquer nova compra.','updated_at'=>now()]);}
 }
 private function purchased(string $id,string $sid,int $actor):void {
  $r=$this->order($id);DB::table('portal_number_requests')->where('id',$id)->update(['provider_sid'=>$sid,'status'=>'purchased','purchased_at'=>now(),'message'=>null,'updated_at'=>now()]);app(Access::class)->audit('number.purchased',$actor,$r->tenant_id,['request'=>$id,'provider_sid'=>$sid]);
 }
 public function adopt(string $id,int $actor,string $number):void {
  $r=$this->order($id);$this->tenant($r->tenant_id);
  abort_unless(preg_match('/^\\+55'.preg_quote($r->ddd,'/').'[0-9]{8}$/D',$number),422,'O número deve ter o DDD solicitado.');
  $n=app(NumberProvider::class)->owned($number);abort_unless($n,422,'Número não encontrado na conta de contratação.');
  abort_if(!empty($n['trunk_sid'])||!empty($n['voice_application_sid'])||!empty($n['voice_url'])||!empty($n['status_callback']),409,'O número já tem uma rota. Nenhuma configuração existente foi alterada.');
  DB::transaction(function()use($r,$n,$actor,$number){
   abort_if(DB::table('portal_number_requests')->where('number',$number)->exists(),409,'Número já associado a outro pedido.');
   abort_unless(DB::table('portal_number_requests')->where('id',$r->id)->whereIn('status',['requested','quoted'])->update(['number'=>$number,'provider_sid'=>$n['sid'],'account_sid'=>$n['account_sid'],'status'=>'purchased','purchased_at'=>now(),'handled_by'=>$actor,'message'=>null,'updated_at'=>now()]),409);
   app(Access::class)->audit('number.existing_linked',$actor,$r->tenant_id,['request'=>$r->id,'number'=>$number]);
  });
 }
 public function reconcile(string $id,int $actor):void {
  $r=$this->order($id);abort_unless(in_array($r->status,['purchasing','uncertain'])&&$r->number,409);
  $n=app(NumberProvider::class)->owned($r->number);
  abort_unless($n&&($n['friendly_name']??'')==='DiscAI pedido '.$id,409,'A compra ainda não foi confirmada. O pedido permanece bloqueado para evitar cobrança duplicada. Confira a conta com a operadora.');
  $this->purchased($id,$n['sid'],$actor);
 }
 public function reject(string $id,int $actor,string $message):void {
  $r=$this->order($id);abort_unless(DB::table('portal_number_requests')->where('id',$id)->whereIn('status',['requested','quoted'])->update(['status'=>'rejected','message'=>$message,'handled_by'=>$actor,'updated_at'=>now()]),409,'Um número contratado não pode ser cancelado por esta ação.');
  app(Access::class)->audit('number.rejected',$actor,$r->tenant_id,['request'=>$id]);
 }
 public function release(string $id,int $actor):void {
  $r=$this->order($id);$t=$this->tenant($r->tenant_id);$provider=app(NumberProvider::class);$c=$provider->config();
  abort_unless(in_array($r->status,['purchased','releasing'])&&$r->account_sid===($c['account_sid']??null),409);
  abort_unless($t->url&&str_starts_with($t->url,'https://')&&$t->secret,422,'Ambiente do cliente ainda não está preparado.');
  // Holding a per-tenant DB lock serializes releases, including additional numbers.
  DB::transaction(function()use($t,$r,$c,$provider,$actor){
   DB::table('portal_tenants')->where('id',$t->id)->lockForUpdate()->first();
   $n=$provider->owned($r->number);abort_unless($n&&$n['sid']===$r->provider_sid,409,'Número não confirmado na operadora.');
   $base=$t->url.'/callbacks/twilio/voice';
   abort_if(!empty($n['trunk_sid'])||!empty($n['voice_application_sid'])||(!empty($n['voice_url'])&&$n['voice_url']!==$base.'/inbound'&&!($n['voice_url']===rtrim(config('discai.portal_url'),'/').'/number-hold'&&($n['friendly_name']??'')==='DiscAI pedido '.$r->id))||(!empty($n['status_callback'])&&$n['status_callback']!==$base.'/inbound/status'),409,'O número possui outra rota. Ela deve ser revisada antes da liberação.');
   DB::table('portal_number_requests')->where('id',$r->id)->update(['status'=>'releasing','updated_at'=>now()]);
   $name='DiscAI tenant '.$t->id.' voice';$conn=DB::table('portal_number_connections')->where('tenant_id',$t->id)->first();
   $ap=$conn?->application_sid;
   if(!$ap){
    $apps=$provider->request('Applications.json',['FriendlyName'=>$name,'PageSize'=>100]);
    abort_if(!empty($apps['next_page_uri']),409,'Revise o catálogo de aplicativos antes de liberar.');
    $matches=collect($apps['applications']??[])->filter(fn($a)=>($a['friendly_name']??'')===$name)->values();abort_if($matches->count()>1,409,'Mais de um aplicativo corresponde à empresa. Revise antes de liberar.');
    $app=$matches->first()??$provider->request('Applications.json',['FriendlyName'=>$name,'VoiceUrl'=>$base.'/dial','VoiceMethod'=>'POST'],true);
    abort_unless(preg_match('/^AP[a-fA-F0-9]{32}$/D',$app['sid']??'')&&($app['voice_url']??'')===$base.'/dial'&&($app['account_sid']??'')===$c['account_sid'],409,'Aplicativo de voz não corresponde ao ambiente.');$ap=$app['sid'];
    DB::table('portal_number_connections')->updateOrInsert(['tenant_id'=>$t->id],['application_sid'=>$ap,'created_at'=>now(),'updated_at'=>now()]);
   }
   $config=array_intersect_key($c,array_flip(['account_sid','auth_token','api_key','api_secret']));
   $config+=['application_sid'=>$ap,'caller_id'=>$r->number,'edge'=>'sao-paulo','enabled'=>true];
   $this->deliver($t,['number'=>$r->number,'provider_sid'=>$r->provider_sid,'request_id'=>$r->id,'connection'=>$config]);
   $provider->request('IncomingPhoneNumbers/'.$r->provider_sid.'.json',['VoiceUrl'=>$base.'/inbound','VoiceMethod'=>'POST','StatusCallback'=>$base.'/inbound/status','StatusCallbackMethod'=>'POST'],true);
   $confirmed=$provider->owned($r->number);
   abort_unless(($confirmed['voice_url']??'')===$base.'/inbound'&&($confirmed['status_callback']??'')===$base.'/inbound/status'&&($confirmed['voice_method']??'')==='POST'&&($confirmed['status_callback_method']??'')==='POST',502,'A operadora ainda não confirmou a configuração. Tente liberar novamente; o número não será comprado outra vez.');
   $this->deliver($t,['number'=>$r->number,'provider_sid'=>$r->provider_sid,'request_id'=>$r->id,'activate'=>true]);
   DB::table('portal_number_requests')->where('id',$r->id)->update(['status'=>'available','released_at'=>now(),'message'=>null,'handled_by'=>$actor,'updated_at'=>now()]);
   app(Access::class)->audit('number.released',$actor,$t->id,['request'=>$r->id,'number'=>$r->number]);
  });
 }
 private function deliver(object $t,array $data):void {
  $path='discai-internal/numbers';$stamp=(string)time();$body=json_encode(['tenant'=>$t->slug]+$data,JSON_UNESCAPED_SLASHES);
  // Secrets are sent only to the provisioned HTTPS tenant origin, never browser supplied URLs.
  $res=Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(15)->withHeaders(['X-Discai-Time'=>$stamp,'X-Discai-Signature'=>hash_hmac('sha256',$stamp.'.'.$path.'.'.$body,Crypt::decryptString($t->secret))])->withBody($body,'application/json')->post($t->url.'/'.$path);
  abort_unless($res->successful()&&$res->json('number')===$data['number'],502,'O ambiente ainda não confirmou a liberação. Tente liberar novamente; nenhuma nova compra será feita.');
 }
}
