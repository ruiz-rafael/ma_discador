<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\Portal\{GoogleMail,Mailboxes};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Crypt};
class GoogleMailHttpTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void {parent::setUp();foreach(glob(database_path('portal-migrations/*.php'))as $m)(require $m)->up();config(['google_mail.client_id'=>'fixture-client','google_mail.client_secret'=>'fixture-secret']);}
 public function test_real_http_exchange_refresh_mime_and_failed_submission_are_not_retried():void {
  foreach(['success','drop','redirect']as $mode){
   $dir=sys_get_temp_dir().'/discai-google-'.bin2hex(random_bytes(6));mkdir($dir,0700);$ready=$dir.'/ready';$log=$dir.'/requests';$err=$dir.'/errors';$pipes=[];$proc=proc_open([PHP_BINARY,base_path('tests/Fixtures/google-mail-http.php'),$ready,$log,$mode],[0=>['pipe','r'],1=>['file',$err,'a'],2=>['file',$err,'a']],$pipes);
   try{
    for($i=0;$i<100&&!is_file($ready);$i++)usleep(10000);$this->assertFileExists($ready);config(['google_mail.test_base'=>'http://'.trim(file_get_contents($ready))]);$g=app(GoogleMail::class);$identity=$g->exchange('fixture-code','fixture-verifier');$this->assertSame('sender@example.test',$identity['email']);$tokens=$identity['tokens'];$tokens['expires_at']=0;$tokens=$g->refresh($tokens);$this->assertSame('fixture-refresh',$tokens['refresh_token']);
    $m=app(Mailboxes::class);$m->ensure('platform');DB::table('portal_mail_connections')->where('scope','platform')->update(['status'=>'connected','enabled'=>true,'revision'=>1,'sender_email'=>$identity['email'],'email'=>$identity['email'],'tokens'=>Crypt::encryptString(json_encode($tokens))]);
    $r=$m->send('platform',$mode,1,'recipient@example.test','Convite em português','Texto com acentos','<a href="https://app.example/register">Criar acesso</a>');$this->assertSame($mode==='success'?'accepted':'unknown',$r['status']);$this->assertSame($r,$m->send('platform',$mode,1,'recipient@example.test','Convite em português','Texto com acentos','<a href="https://app.example/register">Criar acesso</a>'));
    $records=array_map(fn($l)=>json_decode($l,true),file($log,FILE_IGNORE_NEW_LINES));$this->assertSame(['/token','/userinfo','/token','/send'],array_column($records,'path'));parse_str($records[0]['body'],$body);$this->assertSame('fixture-verifier',$body['code_verifier']);$raw=base64_decode(strtr($records[3]['body']['raw'],'-_','+/'));$this->assertStringContainsString('From: sender@example.test',$raw);$this->assertStringContainsString('To: recipient@example.test',$raw);$this->assertStringContainsString('multipart/alternative',$raw);$this->assertStringContainsString('X-DiscAI-Message:',$raw);
   }finally{proc_terminate($proc);foreach($pipes as $p)fclose($p);proc_close($proc);foreach(glob($dir.'/*')as $p)unlink($p);rmdir($dir);}
  }
 }
 public function test_alias_authorization_and_provider_failures_over_real_http():void {
  foreach(['success','sender-revoked','sender-outage','sender-forbidden','sender-redirect'] as $mode){
   $dir=sys_get_temp_dir().'/discai-alias-'.bin2hex(random_bytes(6));mkdir($dir,0700);$ready=$dir.'/ready';$log=$dir.'/requests';$err=$dir.'/errors';$pipes=[];
   $proc=proc_open([PHP_BINARY,base_path('tests/Fixtures/google-mail-http.php'),$ready,$log,$mode],[0=>['pipe','r'],1=>['file',$err,'a'],2=>['file',$err,'a']],$pipes);
   try{
    for($i=0;$i<100&&!is_file($ready);$i++)usleep(10000);$this->assertFileExists($ready);config(['google_mail.test_base'=>'http://'.trim(file_get_contents($ready))]);
    $g=app(GoogleMail::class);$identity=$g->exchange('code','verifier');$m=app(Mailboxes::class);$m->ensure('platform');
    DB::table('portal_mail_connections')->where('scope','platform')->update(['email'=>'sender@example.test','sender_email'=>'commercial@example.test','sender_name'=>'DiscAI','enabled'=>true,'status'=>'connected','revision'=>1,'tokens'=>Crypt::encryptString(json_encode($identity['tokens']))]);
    $result=$m->send('platform','alias-'.$mode,1,'customer@example.test','Invite','Text');$this->assertSame($mode==='success'?'accepted':'blocked',$result['status']);
    $records=array_map(fn($l)=>json_decode($l,true),file($log,FILE_IGNORE_NEW_LINES));$paths=array_column($records,'path');
    $this->assertSame($mode==='success'?['/token','/userinfo','/senders','/send']:['/token','/userinfo','/senders'],$paths);
    if($mode==='success'){$raw=base64_decode(strtr($records[3]['body']['raw'],'-_','+/'));$this->assertStringContainsString('From: DiscAI <commercial@example.test>',$raw);$this->assertStringNotContainsString('sender@example.test',$raw);}
   }finally{proc_terminate($proc);foreach($pipes as $pipe)fclose($pipe);proc_close($proc);foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
  }
 }

}
