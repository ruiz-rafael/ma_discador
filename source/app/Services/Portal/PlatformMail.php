<?php
namespace App\Services\Portal;
use Illuminate\Support\Facades\{DB,Schema,Mail};
use Illuminate\Mail\Mailable;
class PlatformMail {
    public function managed(): bool {return Schema::hasTable('portal_mail_connections')&&DB::table('portal_mail_connections')->where('scope','platform')->exists();}
    public function ready(): bool {
        if($this->managed())return app(Mailboxes::class)->status('platform')['ready'];
        return config('mail.default')==='smtp'&&filled(config('mail.mailers.smtp.host'))&&!in_array(config('mail.mailers.smtp.host'),['127.0.0.1','localhost'])&&filter_var(config('mail.from.address'),FILTER_VALIDATE_EMAIL)&&!str_ends_with(config('mail.from.address'),'@example.com');
    }
    public function send(string $key,string $to,string $subject,string $text,string $html,?Mailable $mailable=null): void {
        abort_unless($this->ready(),422,'Conecte e libere o e-mail administrativo da DiscAI antes de enviar convites.');
        if($this->managed()){
            $s=app(Mailboxes::class)->status('platform');$r=app(Mailboxes::class)->send('platform',$key,$s['revision'],$to,$subject,$text,$html);
            if($r['status']!=='accepted')throw new \RuntimeException('mail_not_confirmed');return;
        }
        config(['mail.mailers.smtp.timeout'=>15]);
        if($mailable){Mail::to($to)->send($mailable);return;}
        Mail::html($html,fn($m)=>$m->to($to)->subject($subject));
    }
}
