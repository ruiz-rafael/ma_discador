<?php
namespace App\Services;
use Illuminate\Support\Facades\Mail;
class CadenceEmailTransport {
 public function ready():bool {
  return (bool)config('cadence_email.enabled') && filled(config('mail.mailers.smtp.host')) && filter_var(config('cadence_email.from'),FILTER_VALIDATE_EMAIL) && filter_var(config('cadence_email.reply_to'),FILTER_VALIDATE_EMAIL) && (int)config('cadence_email.daily_limit')>0;
 }
 public function send(string $id,string $to,string $subject,string $body):void {
  abort_unless($this->ready(),422,'Envio de e-mail não liberado pela administração.');
  // Never allow unbounded SMTP waits or fallback to the log mailer.
  $mailer=Mail::build(array_replace(config('mail.mailers.smtp'),['transport'=>'smtp','timeout'=>15]));
  $mailer->raw($body,function($m)use($to,$subject,$id){$m->from(config('cadence_email.from'),config('cadence_email.name'))->replyTo(config('cadence_email.reply_to'))->to($to)->subject($subject);$m->getSymfonyMessage()->getHeaders()->addTextHeader('X-DiscAI-Message',$id);});
 }
}
