<?php
namespace App\Services\Portal;
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;

class Mailboxes {
    public function authorize(string $scope,int $user): ?object {
        $a=app(Access::class);
        if($scope==='platform'){abort_unless($a->operator($user),403);return null;}
        abort_unless(preg_match('/^tenant:([1-9][0-9]*)$/D',$scope,$m),404);
        $t=DB::table('portal_tenants')->find((int)$m[1]);
        abort_unless($t&&$a->manages($t->id,$user),403);
        abort_if($t->status==='suspended',403,'Empresa suspensa.');return $t;
    }
    public function ensure(string $scope): object {
        DB::table('portal_mail_connections')->insertOrIgnore(['scope'=>$scope,'tenant_id'=>$scope==='platform'?null:(int)substr($scope,7),'created_at'=>now(),'updated_at'=>now()]);
        return DB::table('portal_mail_connections')->where('scope',$scope)->firstOrFail();
    }
    public function status(string $scope): array {
        $c=DB::table('portal_mail_connections')->where('scope',$scope)->first();
        return ['configured'=>(bool)$c,'ready'=>app(GoogleMail::class)->ready()&&$c?->status==='connected'&&(bool)$c?->enabled&&filled($c?->sender_email),
            'driver'=>'google','status'=>$c?->status??'disconnected','enabled'=>(bool)$c?->enabled,'from'=>$c?->sender_email,'reply_to'=>$c?->sender_email,'sender_name'=>$c?->sender_name??'',
            'revision'=>$c?->revision??0,'daily_limit'=>$c?->daily_limit??20];
    }
    public function begin(string $scope,int $user,string $session,bool $senderAccess=false): string {
        $this->authorize($scope,$user);$google=app(GoogleMail::class);abort_unless($google->ready(),422,'O aplicativo Google ainda aguarda configuração pela DiscAI.');
        $c=$this->ensure($scope);$state=bin2hex(random_bytes(32));$verifier=bin2hex(random_bytes(32));
        DB::table('portal_mail_oauth_states')->where('expires_at','<',now()->subDay())->delete();
        DB::table('portal_mail_oauth_states')->insert(['state_hash'=>hash('sha256',$state),'scope'=>$scope,'user_id'=>$user,'session_hash'=>hash('sha256',$session),'verifier'=>Crypt::encryptString($verifier),'revision'=>$c->revision,'expires_at'=>now()->addMinutes(10),'created_at'=>now(),'updated_at'=>now()]);
        return $google->authorization($state,$verifier,$senderAccess);
    }
    public function complete(string $state,int $user,string $session,?string $code,?string $error): string {
        $s=DB::transaction(function()use($state,$user,$session){
            $s=DB::table('portal_mail_oauth_states')->where('state_hash',hash('sha256',$state))->lockForUpdate()->first();
            abort_unless($s&&!$s->used_at&&$s->expires_at>now()&&$s->user_id===$user&&hash_equals($s->session_hash,hash('sha256',$session)),403,'A autorização expirou. Inicie a conexão novamente.');
            $this->authorize($s->scope,$user);
            DB::table('portal_mail_oauth_states')->where('state_hash',$s->state_hash)->update(['used_at'=>now()]);return $s;
        });
        if($error||!$code)throw new \RuntimeException('A autorização não foi concluída. Sua conexão anterior foi preservada.');
        try{$identity=app(GoogleMail::class)->exchange($code,Crypt::decryptString($s->verifier));}
        catch(\Throwable){throw new \RuntimeException('O Google não confirmou todas as permissões. Autorize o envio e tente conectar novamente.');}
        DB::transaction(function()use($s,$identity,$user){
            $this->authorize($s->scope,$user);
            $c=DB::table('portal_mail_connections')->where('scope',$s->scope)->lockForUpdate()->firstOrFail();
            abort_unless($c->revision===$s->revision,409,'A conexão mudou durante a autorização. Inicie novamente.');
            abort_if(DB::table('portal_mail_connections')->where('google_sub',$identity['sub'])->where('scope','!=',$s->scope)->exists(),422,'Esta conta já está vinculada a outro espaço da DiscAI. Use uma conta exclusiva.');
            DB::table('portal_mail_connections')->where('scope',$s->scope)->update(['google_sub'=>$identity['sub'],'email'=>$identity['email'],'sender_email'=>null,'sender_name'=>'','sender_options'=>null,'tokens'=>Crypt::encryptString(json_encode($identity['tokens'])),'status'=>'connected','enabled'=>false,'revision'=>$c->revision+1,'connected_by'=>$user,'connected_at'=>now(),'updated_at'=>now()]);
            app(Access::class)->audit('mail.connected',$user,$c->tenant_id,['scope'=>$s->scope,'email'=>$identity['email']]);
        });
        return $s->scope;
    }
    public function settings(string $scope,int $user,int $revision,bool $enabled,int $limit): void {
        $this->authorize($scope,$user);
        DB::transaction(function()use($scope,$user,$revision,$enabled,$limit){
            $c=DB::table('portal_mail_connections')->where('scope',$scope)->lockForUpdate()->first();
            abort_unless($c&&$c->revision===$revision,409,'A conexão mudou. Atualize a página.');
            abort_if($enabled&&($c->status!=='connected'||!$c->sender_email),422,'Conecte a conta e escolha o remetente antes de liberar envios.');
            DB::table('portal_mail_connections')->where('scope',$scope)->update(['enabled'=>$enabled,'daily_limit'=>$limit,'revision'=>$c->revision+1,'updated_at'=>now()]);
            app(Access::class)->audit('mail.settings',$user,$c->tenant_id,['enabled'=>$enabled,'daily_limit'=>$limit]);
        });
    }
    /** This metadata is only rendered after authorize(), never in tenant bridge status. */
    public function senderDetails(string $scope,int $user): array {
        $this->authorize($scope,$user);
        $c=DB::table('portal_mail_connections')->where('scope',$scope)->first();
        $tokens=$c?->tokens?json_decode(Crypt::decryptString($c->tokens),true):[];
        return ['account'=>$c?->email,'access'=>in_array(GoogleMail::SETTINGS,$tokens['scopes']??[],true),
            'options'=>json_decode($c?->sender_options??'[]',true)?:($c?->status==='connected'?[['email'=>$c->email,'primary'=>true]]:[])];
    }
    private function senderLookup(object $c): array {
        abort_unless($c->status==='connected',422,'Conecte a conta primeiro.');
        $g=app(GoogleMail::class);
        $tokens=$g->refresh(json_decode(Crypt::decryptString($c->tokens),true));
        DB::table('portal_mail_connections')->where('scope',$c->scope)->update(['tokens'=>Crypt::encryptString(json_encode($tokens))]);
        return in_array(GoogleMail::SETTINGS,$tokens['scopes']??[],true)?$g->senders($tokens):[['email'=>$c->email,'primary'=>true]];
    }
    public function updateSenders(string $scope,int $user,int $revision,?string $sender=null,string $name=''): void {
        $this->authorize($scope,$user);
        DB::transaction(function()use($scope,$user,$revision,$sender,$name){
            $c=DB::table('portal_mail_connections')->where('scope',$scope)->lockForUpdate()->first();
            abort_unless($c&&$c->revision===$revision,409,'A conexão mudou. Atualize a página.');
            $options=$this->senderLookup($c);
            $change=['sender_options'=>json_encode($options),'revision'=>$c->revision+1,'updated_at'=>now()];
            if($sender!==null){
                abort_unless(in_array($sender,array_column($options,'email'),true),422,'Este remetente não está confirmado pelo Google. Configure e verifique o endereço no Gmail e atualize a lista.');
                abort_if(strlen($name)>120||preg_match('/[\r\n]/',$name),422,'Nome de remetente inválido.');
                $change+=['sender_email'=>$sender,'sender_name'=>$name,'enabled'=>false];
            }elseif($c->sender_email&&!in_array($c->sender_email,array_column($options,'email'),true)){
                // Keep the selected address visible, pause instead of falling back to the login.
                $change+=['enabled'=>false];
            }
            DB::table('portal_mail_connections')->where('scope',$scope)->update($change);
            app(Access::class)->audit('mail.sender_updated',$user,$c->tenant_id,['sender'=>$sender,'selected'=>$sender!==null]);
        });
    }
    public function disconnect(string $scope,int $user,int $revision): void {
        $this->authorize($scope,$user);
        DB::transaction(function()use($scope,$user,$revision){
            $c=DB::table('portal_mail_connections')->where('scope',$scope)->lockForUpdate()->first();abort_unless($c&&$c->revision===$revision,409,'A conexão mudou. Atualize a página.');
            DB::table('portal_mail_connections')->where('scope',$scope)->update(['tokens'=>null,'google_sub'=>null,'sender_email'=>null,'sender_name'=>'','sender_options'=>null,'enabled'=>false,'status'=>'disconnected','revision'=>$c->revision+1,'updated_at'=>now()]);
            app(Access::class)->audit('mail.disconnected',$user,$c->tenant_id);
        });
    }
    /** Durable idempotency survives HTTP timeouts and worker termination. No automatic resend. */
    public function send(string $scope,string $key,int $revision,string $to,string $subject,string $text,?string $html=null): array {
        $hash=hash('sha256',json_encode([$revision,$to,$subject,$text,$html]));
        [$row,$fresh]=DB::transaction(function()use($scope,$key,$revision,$to,$hash){
            $c=DB::table('portal_mail_connections')->where('scope',$scope)->lockForUpdate()->first();
            abort_unless($c,422,'Conecte o e-mail deste espaço.');
            $old=DB::table('portal_mail_submissions')->where('scope',$scope)->where('request_key',$key)->first();
            if($old){abort_unless(hash_equals($old->payload_hash,$hash),409,'O identificador já foi utilizado com outros dados.');return [$old,false];}
            abort_unless(app(GoogleMail::class)->ready()&&$c->enabled&&filled($c->sender_email)&&$c->status==='connected'&&$c->revision===$revision,422,'A conta foi alterada, desconectada ou está pausada.');
            if($c->tenant_id)abort_unless(DB::table('portal_tenants')->where('id',$c->tenant_id)->where('status','active')->exists(),403);
            $used=DB::table('portal_mail_submissions')->where('scope',$scope)->where('created_at','>=',now()->startOfDay())->count();
            abort_if($used>=min($c->daily_limit,(int)config('google_mail.daily_limit_max')),429,'Limite diário de e-mail atingido.');
            $id=(string)Str::uuid();DB::table('portal_mail_submissions')->insert(['id'=>$id,'scope'=>$scope,'request_key'=>$key,'payload_hash'=>$hash,'destination'=>$to,'connection_revision'=>$revision,'status'=>'submitting','created_at'=>now(),'updated_at'=>now()]);
            return [DB::table('portal_mail_submissions')->find($id),true];
        });
        if(!$fresh)return ['id'=>$row->id,'status'=>$row->status];
        DB::transaction(function()use($row,$revision,$to,$subject,$text,$html){
            $c=DB::table('portal_mail_connections')->where('scope',$row->scope)->lockForUpdate()->firstOrFail();
            $submitted=false;
            try{
                if(!$c->enabled||!$c->sender_email||$c->status!=='connected'||$c->revision!==$revision)throw new \RuntimeException('changed');
                if($c->tenant_id&&!DB::table('portal_tenants')->where('id',$c->tenant_id)->where('status','active')->exists())throw new \RuntimeException('suspended');
                $google=app(GoogleMail::class);$tokens=$google->refresh(json_decode(Crypt::decryptString($c->tokens),true));
                DB::table('portal_mail_connections')->where('scope',$row->scope)->update(['tokens'=>Crypt::encryptString(json_encode($tokens))]);
                if($c->sender_email!==$c->email&&!in_array($c->sender_email,array_column($google->senders($tokens),'email'),true))throw new \RuntimeException('google_sender_revoked');
                $submitted=true;$provider=$google->send($tokens,$c->sender_email,$to,$subject,$text,$html,$row->id,$c->sender_name);
                DB::table('portal_mail_submissions')->where('id',$row->id)->update(['status'=>'accepted','provider_id'=>$provider,'updated_at'=>now()]);
            }catch(\Throwable $e){
                if(in_array($e->getMessage(),['google_sender_revoked','google_sender_scope'],true))DB::table('portal_mail_connections')->where('scope',$row->scope)->update(['enabled'=>false,'revision'=>$c->revision+1,'updated_at'=>now()]);
                if($e->getMessage()==='google_reconnect')DB::table('portal_mail_connections')->where('scope',$row->scope)->update(['status'=>'reconnect','enabled'=>false,'revision'=>$c->revision+1,'updated_at'=>now()]);
                DB::table('portal_mail_submissions')->where('id',$row->id)->update(['status'=>$submitted?'unknown':'blocked','reason'=>$submitted?'O provedor não confirmou o envio. Não haverá reenvio automático.':'A conexão não permitiu iniciar o envio. Confira a conta e seus limites.','updated_at'=>now()]);
            }
        });
        return ['id'=>$row->id,'status'=>DB::table('portal_mail_submissions')->where('id',$row->id)->value('status')];
    }
}
