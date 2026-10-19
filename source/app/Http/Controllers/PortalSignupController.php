<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Services\Portal\{Access,PlatformMail};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB,Hash,Crypt};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
class PortalSignupController extends Controller {
    private function admin(Request $r): void {abort_unless(app(Access::class)->operator($r->user()->id),403);}
    public function index(Request $r){$this->admin($r);return view('portal.signup-invitations',['invitations'=>DB::table('portal_signup_invitations')->latest('created_at')->limit(50)->get(['id','email','company_name','delivery_status','expires_at','accepted_at','revoked_at','tenant_id']),'mailReady'=>app(PlatformMail::class)->ready()]);}
    public function create(Request $r){
        $this->admin($r);$d=$r->validate(['email'=>'required|email:rfc|max:200','company_name'=>'required|string|max:160','plan'=>'required|string|max:80','seats'=>'required|integer|between:1,100','channels'=>'required|integer|between:1,10','storage_mb'=>'required|integer|between:128,102400','delivery'=>'required|in:link,email']);
        abort_if($d['delivery']==='email'&&!app(PlatformMail::class)->ready(),422,'Conecte e libere o e-mail da DiscAI. Você já pode gerar o link para compartilhar.');
        $token=bin2hex(random_bytes(32));$id=(string)Str::uuid();$email=strtolower(trim($d['email']));
        DB::transaction(function()use($d,$r,$token,$id,$email){
            DB::table('users')->where('id',$r->user()->id)->lockForUpdate()->first();
            DB::table('portal_signup_invitations')->where('email',$email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at'=>now(),'token_encrypted'=>null]);
            unset($d['delivery']);$d['email']=$email;
            DB::table('portal_signup_invitations')->insert($d+['id'=>$id,'created_by'=>$r->user()->id,'token_hash'=>hash('sha256',$token),'token_encrypted'=>Crypt::encryptString($token),'expires_at'=>now()->addDays(3),'created_at'=>now(),'updated_at'=>now()]);
            app(Access::class)->audit('signup.invited',$r->user()->id,null,['invitation'=>$id]);
        });
        if($r->input('delivery')==='email')return $this->send($r,$id);
        return redirect('/signup-invitations')->with('invitation',config('discai.portal_url').'/register/company/'.$token)->with('notice','Convite criado. O link é individual, válido por três dias e permite cadastrar uma empresa.');
    }
    private function pending(string $id): object {
        $i=DB::table('portal_signup_invitations')->find($id);abort_unless($i&&!$i->accepted_at&&!$i->revoked_at&&$i->expires_at>now(),410,'Convite expirado, cancelado ou já utilizado.');return $i;
    }
    public function link(Request $r,string $id){$this->admin($r);$i=$this->pending($id);return redirect('/signup-invitations')->with('invitation',config('discai.portal_url').'/register/company/'.Crypt::decryptString($i->token_encrypted));}
    public function send(Request $r,string $id){
        $this->admin($r);$i=$this->pending($id);$service=app(PlatformMail::class);abort_unless($service->ready(),422,'Conecte e libere o e-mail administrativo da DiscAI.');
        abort_unless(DB::table('portal_signup_invitations')->where('id',$id)->where('delivery_status','link')->whereNull('revoked_at')->whereNull('accepted_at')->update(['delivery_status'=>'submitting','updated_at'=>now()])===1,409,'O envio já foi iniciado. Confira o histórico ou compartilhe o link; não haverá reenvio automático.');
        $url=config('discai.portal_url').'/register/company/'.Crypt::decryptString($i->token_encrypted);
        try{
            $html=view('portal.signup-email',['company'=>$i->company_name,'url'=>$url])->render();
            $service->send('signup:'.$id,$i->email,'Seu convite para criar '.$i->company_name.' na DiscAI','Crie sua empresa na DiscAI: '.$url.'. O convite é individual e expira em três dias.',$html);
            $status='accepted';$message='Convite aceito pelo serviço de e-mail. Isso ainda não confirma a entrega ao destinatário.';
        }catch(\Throwable){$status='unconfirmed';$message='O envio não foi confirmado. Confira o histórico ou compartilhe o link. Não haverá reenvio automático.';}
        DB::table('portal_signup_invitations')->where('id',$id)->update(['delivery_status'=>$status,'updated_at'=>now()]);
        app(Access::class)->audit('signup.email_'.$status,$r->user()->id,null,['invitation'=>$id]);
        return redirect('/signup-invitations')->with('notice',$message);
    }
    public function revoke(Request $r,string $id){$this->admin($r);DB::transaction(function()use($id,$r){$i=DB::table('portal_signup_invitations')->where('id',$id)->lockForUpdate()->firstOrFail();abort_if($i->accepted_at,409,'Cadastro já concluído. Gerencie a empresa criada.');DB::table('portal_signup_invitations')->where('id',$id)->update(['revoked_at'=>now(),'token_encrypted'=>null]);app(Access::class)->audit('signup.revoked',$r->user()->id,null,['invitation'=>$id]);});return back()->with('notice','Convite cancelado.');}
    private function invitation(string $token,bool $lock=false): object {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D',$token),404);
        $q=DB::table('portal_signup_invitations')->where('token_hash',hash('sha256',$token));if($lock)$q->lockForUpdate();$i=$q->first();
        abort_unless($i&&!$i->accepted_at&&!$i->revoked_at&&$i->expires_at>now(),410,'Convite expirado, cancelado ou já utilizado. Solicite um novo convite.');
        $issuer=User::find($i->created_by);abort_unless($issuer?->voice_enabled&&app(Access::class)->operator($issuer->id),403,'O convite não está mais disponível.');return $i;
    }
    public function form(string $token){$invite=$this->invitation($token);return response()->view('portal.signup',['invite'=>$invite,'token'=>$token,'exists'=>User::where('email',$invite->email)->exists()])->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');}
    public function accept(Request $r,string $token){
        $d=$r->validate(['name'=>'required|string|max:160','company_name'=>'required|string|max:160','slug'=>['required','regex:/^[a-z][a-z0-9-]{2,38}[a-z0-9]$/D',Rule::notIn(['www','app','admin','api','mail','login','support','oauth','register','discai','zyrex'])],'password'=>'required|string|max:200','password_confirmation'=>'required|same:password']);
        try{$uid=DB::transaction(function()use($token,$d){
            $i=$this->invitation($token,true);
            if(DB::table('portal_tenants')->where('slug',$d['slug'])->exists())throw \Illuminate\Validation\ValidationException::withMessages(['slug'=>'Este identificador já está em uso. Escolha outro.']);
            $u=User::where('email',$i->email)->lockForUpdate()->first();
            if($u){if(!$u->voice_enabled||!Hash::check($d['password'],$u->password))throw \Illuminate\Validation\ValidationException::withMessages(['password'=>'Use a senha atual da sua conta DiscAI.']);}
            else{\Illuminate\Support\Facades\Validator::make($d,['password'=>'required|min:12'])->validate();$u=User::create(['name'=>$d['name'],'email'=>$i->email,'password'=>$d['password']]);}
            $tid=DB::table('portal_tenants')->insertGetId(['name'=>$d['company_name'],'slug'=>$d['slug'],'status'=>'provisioning','plan'=>$i->plan,'seats'=>$i->seats,'channels'=>$i->channels,'storage_mb'=>$i->storage_mb,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('portal_memberships')->insert(['tenant_id'=>$tid,'user_id'=>$u->id,'role'=>'admin','active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('portal_signup_invitations')->where('id',$i->id)->update(['accepted_at'=>now(),'tenant_id'=>$tid,'token_encrypted'=>null,'updated_at'=>now()]);
            app(Access::class)->audit('signup.accepted',$u->id,$tid,['invitation'=>$i->id]);return $u->id;
        });}catch(\Illuminate\Database\QueryException){return back()->withErrors(['registration'=>'O cadastro mudou durante o processamento. Atualize a página e confira o identificador escolhido.'])->withInput($r->only('name','company_name','slug'));}
        Auth::loginUsingId($uid);$r->session()->regenerate();$r->session()->put('voice_auth_version',(int)$r->user()->voice_auth_version);
        return redirect('/companies')->with('notice','Sua empresa foi cadastrada e você é o administrador. O ambiente está em preparação; domínio, canais e operação serão liberados após a configuração.');
    }
}
