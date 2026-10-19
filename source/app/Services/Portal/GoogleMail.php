<?php
namespace App\Services\Portal;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Email;

/** Narrow Google HTTP contract. No redirect following, retry or raw provider errors. */
class GoogleMail {
    public const SEND = 'https://www.googleapis.com/auth/gmail.send';
    public function credentials(): array {
        $path=storage_path('app/private/google-oauth.json');
        $c=is_file($path)?(json_decode(file_get_contents($path),true)['web']??[]):[];
        return ['client_id'=>$c['client_id']??config('google_mail.client_id'),'client_secret'=>$c['client_secret']??config('google_mail.client_secret')];
    }
    public function ready(): bool { $c=$this->credentials();return filled($c['client_id'])&&filled($c['client_secret']); }
    public function callback(): string { return rtrim(config('discai.portal_url'),'/').'/oauth/google/callback'; }
    public function endpoint(string $name): string {
        $paths=['token'=>'https://oauth2.googleapis.com/token','userinfo'=>'https://openidconnect.googleapis.com/v1/userinfo','send'=>'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'];
        if(app()->environment('testing')&&config('google_mail.test_base'))return rtrim(config('google_mail.test_base'),'/').'/'.$name;
        return $paths[$name];
    }
    private function http() { return Http::connectTimeout(3)->timeout(12)->withOptions(['allow_redirects'=>false])->acceptJson(); }
    public function authorization(string $state,string $verifier): string {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id'=>$this->credentials()['client_id'],'redirect_uri'=>$this->callback(),'response_type'=>'code',
            'scope'=>'openid email '.self::SEND,'access_type'=>'offline','prompt'=>'consent select_account',
            'state'=>$state,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256',
        ],'', '&',PHP_QUERY_RFC3986);
    }
    public function exchange(string $code,string $verifier): array {
        $r=$this->http()->asForm()->post($this->endpoint('token'),$this->credentials()+['grant_type'=>'authorization_code','code'=>$code,'code_verifier'=>$verifier,'redirect_uri'=>$this->callback()]);
        if(!$r->successful())throw new \RuntimeException('google_authorization');
        $t=$r->json();
        if(!is_array($t)||empty($t['access_token'])||empty($t['refresh_token'])||!in_array(self::SEND,explode(' ',$t['scope']??''),true))throw new \RuntimeException('google_scope');
        $u=$this->http()->withToken($t['access_token'])->get($this->endpoint('userinfo'));
        $identity=$u->json();
        if(!$u->successful()||!is_array($identity)||($identity['email_verified']??false)!==true||empty($identity['sub'])||!filter_var($identity['email']??'',FILTER_VALIDATE_EMAIL))throw new \RuntimeException('google_identity');
        return ['sub'=>$identity['sub'],'email'=>strtolower($identity['email']),'tokens'=>['access_token'=>$t['access_token'],'refresh_token'=>$t['refresh_token'],'expires_at'=>time()+max(0,(int)($t['expires_in']??0))]];
    }
    public function refresh(array $t): array {
        if(($t['expires_at']??0)>time()+60)return $t;
        $r=$this->http()->asForm()->post($this->endpoint('token'),$this->credentials()+['grant_type'=>'refresh_token','refresh_token'=>$t['refresh_token']]);
        if(!$r->successful())throw new \RuntimeException($r->json('error')==='invalid_grant'?'google_reconnect':'google_unavailable');
        $d=$r->json();
        if(empty($d['access_token'])||(isset($d['scope'])&&!in_array(self::SEND,explode(' ',$d['scope']),true)))throw new \RuntimeException('google_reconnect');
        return ['access_token'=>$d['access_token'],'refresh_token'=>$d['refresh_token']??$t['refresh_token'],'expires_at'=>time()+max(0,(int)($d['expires_in']??0))];
    }
    public function send(array $tokens,string $from,string $to,string $subject,string $text,?string $html,string $id): string {
        $message=(new Email())->from($from)->replyTo($from)->to($to)->subject($subject)->text($text);
        if($html!==null)$message->html($html);
        $message->getHeaders()->addTextHeader('X-DiscAI-Message',$id);
        $raw=rtrim(strtr(base64_encode($message->toString()),'+/','-_'),'=');
        $r=$this->http()->withToken($tokens['access_token'])->post($this->endpoint('send'),['raw'=>$raw]);
        if($r->status()===401)throw new \RuntimeException('google_reconnect');
        if(!$r->successful()||!is_string($r->json('id')))throw new \RuntimeException('google_send_unconfirmed');
        return $r->json('id');
    }
}
