<?php
namespace App\Services\Portal;
use App\Models\User;
use App\Mail\PortalInvitation;
use Illuminate\Support\Facades\{DB,Mail};
class Invitations {
 public function mailReady():bool {return app(PlatformMail::class)->ready();}
 public function create(int $tenant,int $actor,string $email,string $role,string $delivery='link'):array {
  abort_unless(in_array($role,['admin','supervisor','agent'],true)&&in_array($delivery,['link','email'],true),422);$email=strtolower(trim($email));$access=app(Access::class);abort_unless($access->assigns($tenant,$actor,$role),403,'Somente administradores podem convidar pessoas para esta empresa.');
  abort_if($delivery==='email'&&!$this->mailReady(),422,'O envio por e-mail ainda não está configurado. Gere o link para compartilhar.');
  $token=bin2hex(random_bytes(32));$url=config('discai.portal_url').'/invite/'.$token;
  [$id,$name]=DB::transaction(function()use($tenant,$actor,$email,$role,$token,$access){
   $t=DB::table('portal_tenants')->where('id',$tenant)->lockForUpdate()->firstOrFail();abort_if($t->status==='suspended',409,'A empresa está suspensa.');
   $user=User::where('email',$email)->first();abort_if($user&&$access->member($tenant,$user->id),422,'Esta pessoa já possui acesso. Altere o perfil na equipe.');
   $pending=DB::table('portal_invitations')->where('tenant_id',$tenant)->where('email',$email)->whereNull('accepted_at')->where('expires_at','>',now())->first();abort_if($pending&&!$access->assigns($tenant,$actor,$pending->role),403,'Este convite deve ser gerenciado por um administrador.');
   $active=DB::table('portal_memberships')->where('tenant_id',$tenant)->where('active',true)->count();$reserved=DB::table('portal_invitations')->where('tenant_id',$tenant)->whereNull('accepted_at')->where('expires_at','>',now())->where('email','!=',$email)->count();abort_if($active+$reserved>=$t->seats,422,'Limite de usuários atingido.');
   DB::table('portal_invitations')->where('tenant_id',$tenant)->where('email',$email)->whereNull('accepted_at')->update(['expires_at'=>now()]);
   $id=DB::table('portal_invitations')->insertGetId(['tenant_id'=>$tenant,'email'=>$email,'role'=>$role,'created_by'=>$actor,'token_hash'=>hash('sha256',$token),'expires_at'=>now()->addDays(3),'created_at'=>now(),'updated_at'=>now()]);return [$id,$t->name];
  });
  $access->audit('invitation.created',$actor,$tenant,['email'=>$email,'role'=>$role,'delivery'=>$delivery]);
  $message='Convite criado. Compartilhe o link com a pessoa. Válido por três dias.';
  if($delivery==='email'){
   // A timeout can mean delivery succeeded; never retry automatically.
   try{app(PlatformMail::class)->send('team:'.$id,$email,'Seu convite para '.$name.' na DiscAI','Aceite seu convite: '.$url,view('portal.invitation-email',['company'=>$name,'role'=>$role,'invitationUrl'=>$url])->render(),new PortalInvitation($name,$role,$url));DB::table('portal_invitations')->where('id',$id)->update(['delivery_status'=>'sent','emailed_at'=>now()]);$access->audit('invitation.emailed',$actor,$tenant,['invitation'=>$id]);$message='Convite enviado ao serviço de e-mail. O link também está disponível abaixo.';}
   catch(\Throwable){DB::table('portal_invitations')->where('id',$id)->update(['delivery_status'=>'unconfirmed']);$access->audit('invitation.email_unconfirmed',$actor,$tenant,['invitation'=>$id]);$message='Não foi possível confirmar o envio do e-mail. O convite continua válido; compartilhe o link abaixo.';}
  }
  return ['id'=>$id,'url'=>$url,'message'=>$message];
 }
}
