<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\Portal\AdministrativeDialer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Crypt};
class PortalCallingHttpTest extends TestCase {
 use RefreshDatabase;
 public function test_real_signed_transport_drop_redirect_and_revocation_do_not_repeat_reservation():void {
  foreach(glob(database_path('portal-migrations/*.php'))as $m)(require $m)->up();$u=User::factory()->create();DB::table('portal_operators')->insert(['user_id'=>$u->id]);$tenant=DB::table('portal_tenants')->insertGetId(['slug'=>'fixture','name'=>'Fixture','status'=>'active','url'=>'https://tenant.example','secret'=>Crypt::encryptString('fixture-secret')]);
  foreach(['success','drop','redirect','forbidden']as $mode){
   $dir=sys_get_temp_dir().'/discai-calling-'.bin2hex(random_bytes(6));mkdir($dir,0700);$pipes=[];$proc=proc_open([PHP_BINARY,base_path('tests/Fixtures/platform-calling-http.php'),$dir.'/ready',$dir.'/log',$mode],[0=>['pipe','r'],1=>['file',$dir.'/error','a'],2=>['file',$dir.'/error','a']],$pipes);
   try{
    for($i=0;$i<100&&!is_file($dir.'/ready');$i++)usleep(10000);$this->assertFileExists($dir.'/ready');config(['platform_calling_test_base'=>'http://'.trim(file_get_contents($dir.'/ready'))]);
    $d=['route'=>Crypt::encryptString(json_encode(['tenant'=>$tenant,'user'=>$u->id,'number'=>'+551130000001','method'=>'programmable_voice','until'=>time()+3600])),'request_id'=>(string)\Illuminate\Support\Str::uuid(),'destination'=>'+5511999990001','consent_confirmed'=>true,'consent_evidence'=>'Own test phone'];$svc=app(AdministrativeDialer::class);
    try{$g=$svc->create($u->id,$d);$this->assertSame('success',$mode);$this->assertSame('fixture-private-token',$g['grant']['access_token']);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame($mode==='forbidden'?403:503,$e->getStatusCode());}
    $row=DB::table('portal_test_calls')->find($d['request_id']);$this->assertSame(match($mode){'success'=>'active','forbidden'=>'ended',default=>'unknown'},$row->state);
    try{$svc->create($u->id,$d);$this->fail('No replay');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(409,$e->getStatusCode());}
    $result=$svc->action($u->id,$d['request_id'],'status');$this->assertTrue($result['call']['ended']);
    $records=array_map(fn($l)=>json_decode($l,true),file($dir.'/log',FILE_IGNORE_NEW_LINES));$this->assertSame($mode==='forbidden'?['reserve']:['reserve','status'],array_column($records,'action'));$this->assertNotContains(false,array_column($records,'signed'));
   }finally{proc_terminate($proc);foreach($pipes as $p)fclose($p);proc_close($proc);foreach(glob($dir.'/*')as $f)unlink($f);rmdir($dir);}
  }
 }
}
