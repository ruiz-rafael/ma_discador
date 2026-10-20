<?php
namespace App\Services\Portal;
use Illuminate\Support\Facades\{Crypt,Http};
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

/** Only the central portal holds the purchasing credentials. No automatic retries on writes. */
class NumberProvider {
 public function config():?array {
  if(app()->environment('testing'))return config('portal_numbers_test');
  $path=storage_path('app/private/number-provider.enc');
  return is_readable($path)?json_decode(Crypt::decryptString(file_get_contents($path)),true,512,JSON_THROW_ON_ERROR):null;
 }
 public function request(string $path,array $data=[],bool $write=false):array {
  $c=$this->config();abort_unless($c,503,'A conexão de contratação ainda não está configurada pela administração.');
  $url=str_starts_with($path,'https://')?$path:'https://api.twilio.com/2010-04-01/Accounts/'.$c['account_sid'].'/'.$path;
  abort_unless(in_array(parse_url($url,PHP_URL_HOST),['api.twilio.com','pricing.twilio.com','numbers.twilio.com'],true),500);
  $h=Http::withBasicAuth($c['account_sid'],$c['auth_token'])->asForm()->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(15);
  try{$r=$write?$h->post($url,$data):$h->get($url,$data);}catch(ConnectionException){throw new \RuntimeException('provider_unknown');}
  if(!$r->successful()){
   if(!$r->clientError())throw new \RuntimeException('provider_unknown');
   throw ValidationException::withMessages(['provider'=>'A operadora não autorizou esta operação (código '.(int)$r->json('code').'). Confira o cadastro regulatório, a disponibilidade e o saldo.']);
  }
  $result=$r->json();if(!is_array($result))throw new \RuntimeException('provider_unknown');return $result;
 }
 public function options(string $ddd):array {
  // AreaCode is US/Canada only. For Brazil use the complete international prefix.
  $r=$this->request('AvailablePhoneNumbers/BR/Local.json',['Contains'=>'+55'.$ddd,'VoiceEnabled'=>'true','PageSize'=>20]);
  $numbers=collect($r['available_phone_numbers']??[])->filter(fn($n)=>preg_match('/^\+55'.preg_quote($ddd,'/').'[0-9]{8}$/D',$n['phone_number']??'')&&!empty($n['capabilities']['voice']))->map(fn($n)=>['number'=>$n['phone_number'],'locality'=>$n['locality']??'','address_required'=>$n['address_requirements']??''])->values()->all();
  $price=$this->request('https://pricing.twilio.com/v1/PhoneNumbers/Countries/BR');
  $local=collect($price['phone_number_prices']??[])->firstWhere('number_type','local');
  abort_unless($local&&is_numeric($local['current_price']??null)&&preg_match('/^[A-Z]{3}$/D',strtoupper($price['price_unit']??'')),422,'Não foi possível confirmar o preço. Solicite uma cotação à operadora.');
  return ['numbers'=>$numbers,'monthly'=>(string)$local['current_price'],'currency'=>strtoupper($price['price_unit']),'account_sid'=>$this->config()['account_sid']];
 }
 public function registrations():array {
  $b=$this->request('https://numbers.twilio.com/v2/RegulatoryCompliance/Bundles',['Status'=>'twilio-approved','PageSize'=>100]);
  $a=$this->request('Addresses.json',['PageSize'=>100]);
  return ['bundles'=>collect($b['results']??[])->filter(fn($v)=>($v['status']??null)==='twilio-approved')->map(fn($v)=>['sid'=>$v['sid'],'name'=>$v['friendly_name']??$v['sid']])->values()->all(),
   'addresses'=>collect($a['addresses']??[])->filter(fn($v)=>($v['iso_country']??null)==='BR')->map(fn($v)=>['sid'=>$v['sid'],'name'=>($v['friendly_name']??$v['customer_name']??'Endereço').' · '.($v['city']??'')])->values()->all()];
 }
 /** Purchased inventory only: this never searches or buys new numbers. */
 public function inventory():array {
  $c=$this->config();abort_unless($c,503);$path='IncomingPhoneNumbers.json';$query=['PageSize'=>1000];$result=[];$seen=[];
  for($page=0;$page<20;$page++){
   $r=$this->request($path,$query);
   abort_unless(isset($r['incoming_phone_numbers'])&&is_array($r['incoming_phone_numbers']),502,'Inventário da operadora não confirmado.');
   foreach($r['incoming_phone_numbers'] as $n){
    abort_unless(($n['account_sid']??null)===$c['account_sid']&&preg_match('/^PN[a-fA-F0-9]{32}$/D',$n['sid']??'')&&preg_match('/^\+[1-9][0-9]{7,14}$/D',$n['phone_number']??''),502,'Inventário da conta não confirmado.');
    $result[$n['phone_number']]=$n;
   }
   $next=$r['next_page_uri']??null;if(!$next)return array_values($result);
   $prefix='/2010-04-01/Accounts/'.$c['account_sid'].'/IncomingPhoneNumbers.json?';
   abort_unless(is_string($next)&&str_starts_with($next,$prefix)&&!isset($seen[$next]),502,'Paginação do inventário não confirmada.');
   $seen[$next]=true;$path='https://api.twilio.com'.parse_url($next,PHP_URL_PATH);parse_str(parse_url($next,PHP_URL_QUERY),$query);
  }
  abort(502,'O inventário excedeu o limite desta consulta.');
 }
 public function owned(string $number):?array {
  $r=$this->request('IncomingPhoneNumbers.json',['PhoneNumber'=>$number,'PageSize'=>2]);
  $all=$r['incoming_phone_numbers']??[];abort_if(count($all)>1||!empty($r['next_page_uri']),409,'A consulta retornou mais de um número.');
  if(!$all)return null;$n=$all[0];
  abort_unless(($n['phone_number']??null)===$number&&($n['account_sid']??null)===$this->config()['account_sid']&&preg_match('/^PN[a-fA-F0-9]{32}$/D',$n['sid']??'')&&!empty($n['capabilities']['voice']),422,'Número não confirmado para voz na conta de contratação.');
  return $n;
 }
 public function purchase(object $r):array {
  $bundle=$this->request('https://numbers.twilio.com/v2/RegulatoryCompliance/Bundles/'.$r->bundle_sid);
  abort_unless(($bundle['status']??null)==='twilio-approved'&&($bundle['account_sid']??null)===$this->config()['account_sid'],422,'O cadastro regulatório precisa estar aprovado nesta conta.');
  if(!empty($bundle['valid_until']))abort_if(\Carbon\Carbon::parse($bundle['valid_until'])->isPast(),422,'O cadastro regulatório venceu.');
  return $this->request('IncomingPhoneNumbers.json',array_filter(['PhoneNumber'=>$r->number,'FriendlyName'=>'DiscAI pedido '.$r->id,'VoiceUrl'=>rtrim(config('discai.portal_url'),'/').'/number-hold','VoiceMethod'=>'POST','BundleSid'=>$r->bundle_sid,'AddressSid'=>$r->address_sid]),true);
 }
}
