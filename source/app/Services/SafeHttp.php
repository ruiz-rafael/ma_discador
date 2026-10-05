<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
class SafeHttp {
 public function validate(string $url):array {
  $u=parse_url($url);if(!$u||($u['scheme']??'')!=='https'||isset($u['user'])||isset($u['pass'])||isset($u['fragment'])||($u['port']??443)!==443||!in_array($u['host']??'',config('marketing.allowed_hosts')))throw new \RuntimeException('URL HTTPS deve usar um host autorizado nas integrações.');
  $ips=gethostbynamel($u['host'])?:[];if(!$ips)throw new \RuntimeException('Host não resolvido.');foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new \RuntimeException('Endereço privado ou reservado bloqueado.');return [$u['host'],$ips[0]];
 }
 public function send(string $url,string $method,array $payload,string $key,?string $bearer=null):array {
  [$host,$ip]=$this->validate($url);$method=strtoupper($method);if(!in_array($method,['GET','POST','PUT']))throw new \RuntimeException('Método não autorizado.');
  $request=Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->withOptions(['curl'=>[CURLOPT_RESOLVE=>["$host:443:$ip"]]])->withHeaders(['Idempotency-Key'=>$key]);if($bearer)$request=$request->withToken($bearer);
  $response=$request->send($method,$url,[$method==='GET'?'query':'json'=>$payload]);if(!$response->successful())throw new \RuntimeException('Integração retornou HTTP '.$response->status());return ['http_status'=>$response->status(),'data'=>$response->json()];
 }
}
