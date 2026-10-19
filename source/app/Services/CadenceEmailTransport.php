<?php
namespace App\Services;
use App\Services\Portal\TenantBridge;
use Illuminate\Support\Facades\Mail;
class CadenceEmailTransport {
 public function info():array {
  $managed=[];$bridge=app(TenantBridge::class);
  if($bridge->config()){
   try{$managed=$bridge->call('mail/status',[]);if($managed['configured']??false)return $managed;}
   catch(\Throwable){return ['ready'=>false,'driver'=>'google','from'=>null,'reply_to'=>null,'daily_limit'=>0,'revision'=>0,'message'=>'Não foi possível conferir a conexão de e-mail. Nenhum remetente alternativo será utilizado.'];}
  }
  return ['ready'=>(bool)config('cadence_email.enabled')&&filled(config('mail.mailers.smtp.host'))&&(bool)filter_var(config('cadence_email.from'),FILTER_VALIDATE_EMAIL)&&(bool)filter_var(config('cadence_email.reply_to'),FILTER_VALIDATE_EMAIL)&&(int)config('cadence_email.daily_limit')>0,'driver'=>'smtp','from'=>config('cadence_email.from'),'reply_to'=>config('cadence_email.reply_to'),'daily_limit'=>(int)config('cadence_email.daily_limit'),'revision'=>0,'manage_url'=>$managed['manage_url']??null];
 }
 public function ready():bool {return $this->info()['ready'];}
 public function send(string $id,string $to,string $subject,string $body,?array $connection=null):void {
  $c=$connection??$this->info();abort_unless($c['ready'],422,'Envio de e-mail não liberado pela administração.');
  if($c['driver']==='google'){
   $r=app(TenantBridge::class)->call('mail/send',['request_key'=>$id,'revision'=>$c['revision'],'to'=>$to,'subject'=>$subject,'body'=>$body]);
   if(($r['status']??null)!=='accepted')throw new \RuntimeException('mail_not_confirmed');return;
  }
  $mailer=Mail::build(array_replace(config('mail.mailers.smtp'),['transport'=>'smtp','timeout'=>15]));
  $mailer->raw($body,function($m)use($to,$subject,$id,$c){$m->from($c['from'],config('cadence_email.name'))->replyTo($c['reply_to'])->to($to)->subject($subject);$m->getSymfonyMessage()->getHeaders()->addTextHeader('X-DiscAI-Message',$id);});
 }
}
