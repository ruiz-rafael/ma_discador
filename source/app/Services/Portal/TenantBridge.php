<?php
namespace App\Services\Portal;
use Illuminate\Support\Facades\Http;
class TenantBridge {
 public function config():?array {if(app()->environment('testing')&&config('discai_bridge_test'))return config('discai_bridge_test');$path=storage_path('app/private/discai-portal.json');if(!is_file($path))return null;return json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}
 public function call(string $action,array $data):array {$c=$this->config();abort_unless($c,404);$path='portal-internal/'.$action;$body=json_encode(['tenant'=>$c['slug']]+$data,JSON_UNESCAPED_SLASHES);$stamp=(string)time();try{$res=Http::timeout($action==='mail/send'?30:5)->connectTimeout(2)->withOptions(['allow_redirects'=>false])->withHeaders(['Accept'=>'application/json','X-Discai-Time'=>$stamp,'X-Discai-Signature'=>hash_hmac('sha256',$stamp.'.'.$path.'.'.$body,$c['secret'])])->withBody($body,'application/json')->post($c['internal_url'].'/'.$path);}catch(\Throwable){abort(503,'Não foi possível verificar o acesso. Tente novamente.');}abort_unless($res->successful(),$res->status()>=500?503:403,'Acesso à empresa indisponível.');$d=$res->json();abort_unless(is_array($d)&&($d['tenant']??null)===$c['slug']&&in_array($d['role']??null,['admin','supervisor','agent'],true),403);return $d;}
}
