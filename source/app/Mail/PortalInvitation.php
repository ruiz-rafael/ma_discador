<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
class PortalInvitation extends Mailable {
 public function __construct(public string $company,public string $role,public string $invitationUrl){}
 public function build(){return $this->subject('Seu convite para '.$this->company.' na DiscAI')->view('portal.invitation-email');}
}
