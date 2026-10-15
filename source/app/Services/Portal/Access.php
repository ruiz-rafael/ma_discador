<?php
namespace App\Services\Portal;
use App\Models\User;
use Illuminate\Support\Facades\{DB,Crypt};
class Access {
 public function operator(int $id):bool{return DB::table('portal_operators')->where('user_id',$id)->exists();}
 public function member(int $tenant,int $user){return DB::table('portal_memberships')->where('tenant_id',$tenant)->where('user_id',$user)->where('active',true)->first();}
 public function audit(string $event,?int $user,?int $tenant,array $details=[]):void {DB::table('portal_audit')->insert(['event'=>$event,'user_id'=>$user,'tenant_id'=>$tenant,'details'=>json_encode($details),'created_at'=>now()]);}
 public function manages(int $tenant,int $user):bool {return $this->operator($user)||$this->member($tenant,$user)?->role==='admin';}
 public function assigns(int $tenant,int $user,string $role):bool {return $this->operator($user)||$this->member($tenant,$user)?->role==='admin';}
 public function identity(int $tenant,int $user):array {
  $t=DB::table('portal_tenants')->find($tenant);$u=User::find($user);$m=$this->member($tenant,$user);$operator=$this->operator($user);
  abort_unless($t&&$t->status==='active'&&$u&&$u->voice_enabled&&($operator||$m),403,'Acesso à empresa indisponível.');
  return ['id'=>$u->id,'name'=>$u->name,'email'=>$u->email,'role'=>$operator?'admin':$m->role,'platform_admin'=>$operator,'management_url'=>config('discai.portal_url').'/administration/'.$t->id,'version'=>(int)$u->voice_auth_version,'tenant'=>$t->slug,'limits'=>['seats'=>$t->seats,'channels'=>$t->channels,'storage_mb'=>$t->storage_mb]];
 }
 public function authenticateTenant(\Illuminate\Http\Request $r){
  $d=$r->validate(['tenant'=>'required|string|max:48']);$t=DB::table('portal_tenants')->where('slug',$d['tenant'])->first();
  $stamp=$r->header('X-Discai-Time','');$sig=$r->header('X-Discai-Signature','');
  abort_unless($t&&$t->secret&&ctype_digit($stamp)&&abs(time()-(int)$stamp)<=30&&strlen($r->getContent())<=4096,401);
  abort_unless(hash_equals(hash_hmac('sha256',$stamp.'.'.$r->path().'.'.$r->getContent(),Crypt::decryptString($t->secret)),$sig),401);
  return $t;
 }
}
