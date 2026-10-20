<?php
namespace App\Http\Controllers;
use App\Services\Portal\{Mailboxes,GoogleMail,Access};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class PortalMailController extends Controller {
    private function scope(?int $tenant): string{return $tenant?'tenant:'.$tenant:'platform';}
    public static function location(string $scope): string{return $scope==='platform'?'/email-administration':'/companies/'.substr($scope,7).'/email';}
    public function show(Request $r,Mailboxes $m,?int $tenant=null){
        $scope=$this->scope($tenant);$company=$m->authorize($scope,$r->user()->id);
        return view('portal.email',['company'=>$company,'scope'=>$scope,'path'=>self::location($scope),'senderDetails'=>$m->senderDetails($scope,$r->user()->id),'connection'=>$m->status($scope),'oauthReady'=>app(GoogleMail::class)->ready(),'callback'=>app(GoogleMail::class)->callback(),'operator'=>app(Access::class)->operator($r->user()->id),'history'=>DB::table('portal_mail_submissions')->where('scope',$scope)->latest('created_at')->limit(30)->get(['destination','status','reason','created_at'])]);
    }
    public function connect(Request $r,Mailboxes $m,?int $tenant=null){return redirect()->away($m->begin($this->scope($tenant),$r->user()->id,$r->session()->getId(),$r->boolean('sender_access')));}
    public function senders(Request $r,Mailboxes $m,?int $tenant=null){
        $d=$r->validate(['revision'=>'required|integer|min:0','sender'=>'nullable|email:rfc|max:254','sender_name'=>'nullable|string|max:120|not_regex:/[\r\n]/']);
        try{$m->updateSenders($this->scope($tenant),$r->user()->id,$d['revision'],isset($d['sender'])?strtolower($d['sender']):null,$d['sender_name']??'');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){throw $e;}
        catch(\RuntimeException $e){
            $message=match($e->getMessage()){
                'google_sender_scope'=>'Autorize a consulta de remetentes no botão “Autorizar remetentes do Gmail”. Se já autorizou, confira se a Gmail API está habilitada no projeto Google.',
                'google_reconnect'=>'A autorização expirou. Reconecte sua conta Google.',
                default=>'Não foi possível consultar os remetentes no Google. Tente atualizar novamente. Nenhum envio foi liberado.',
            };
            return back()->withErrors(['sender'=>$message]);
        }
        return back()->with('notice',isset($d['sender'])?'Remetente salvo. Os envios estão pausados; confira o endereço antes de liberar.':'Lista de remetentes atualizada.');
    }
    public function save(Request $r,Mailboxes $m,?int $tenant=null){
        $d=$r->validate(['revision'=>'required|integer|min:0','enabled'=>'required|boolean','daily_limit'=>'required|integer|between:1,'.config('google_mail.daily_limit_max')]);
        $m->settings($this->scope($tenant),$r->user()->id,$d['revision'],$d['enabled'],$d['daily_limit']);return back()->with('notice','Preferências salvas. Nenhum e-mail foi disparado por esta ação.');
    }
    public function disconnect(Request $r,Mailboxes $m,?int $tenant=null){$d=$r->validate(['revision'=>'required|integer|min:0']);$m->disconnect($this->scope($tenant),$r->user()->id,$d['revision']);return back()->with('notice','Conta desconectada da DiscAI. Você também pode remover a autorização nas configurações da sua Conta Google.');}
    public function callback(Request $r,Mailboxes $m){
        $d=$r->validate(['state'=>'required|regex:/^[a-f0-9]{64}$/D','code'=>'nullable|string|max:4096','error'=>'nullable|string|max:120']);
        try{$scope=$m->complete($d['state'],$r->user()->id,$r->session()->getId(),$d['code']??null,$d['error']??null);}
        catch(\Illuminate\Database\QueryException){return redirect('/companies')->withErrors(['oauth'=>'Esta conta não pôde ser vinculada. Confira se ela já está em uso em outro espaço.']);}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){return redirect('/companies')->withErrors(['oauth'=>$e->getMessage()]);}
        catch(\Throwable){return redirect('/companies')->withErrors(['oauth'=>'Não foi possível concluir a autorização. Confira as permissões e inicie a conexão novamente.']);}
        return redirect(self::location($scope))->with('notice','Conta conectada. Escolha e salve o remetente antes de liberar os envios.');
    }
    public function internal(Request $r,Mailboxes $m,Access $a,string $action){
        $t=$a->authenticateTenant($r);abort_unless($t->status==='active',403);$scope='tenant:'.$t->id;
        if($action==='status')return ['tenant'=>$t->slug,'role'=>'admin']+$m->status($scope)+['manage_url'=>rtrim(config('discai.portal_url'),'/').self::location($scope)];
        $d=$r->validate(['request_key'=>'required|uuid','revision'=>'required|integer|min:1','to'=>'required|email:rfc|max:254','subject'=>'required|string|max:200','body'=>'required|string|max:20000']);
        abort_if(preg_match('/[\r\n]/',$d['subject']),422);
        $result=$m->send($scope,'cadence:'.$d['request_key'],$d['revision'],$d['to'],$d['subject'],$d['body']);
        return ['tenant'=>$t->slug,'role'=>'admin']+$result;
    }
}
