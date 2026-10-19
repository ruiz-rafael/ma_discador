<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Services\CadenceEmailTransport;
use Illuminate\Support\Facades\Http;
class ManagedCadenceMailTest extends TestCase {
 protected function setUp():void {parent::setUp();Http::preventStrayRequests();config(['discai_bridge_test'=>['slug'=>'example','internal_url'=>'https://portal.example','secret'=>'fixture-secret'],'cadence_email.enabled'=>true,'cadence_email.from'=>'fallback@example.test','cadence_email.reply_to'=>'fallback@example.test','cadence_email.daily_limit'=>20,'mail.mailers.smtp.host'=>'smtp.example.test']);}
 public function test_managed_connection_uses_signed_tenant_bridge_and_pins_revision():void {
  Http::fake(['https://portal.example/portal-internal/mail/status'=>Http::response(['tenant'=>'example','role'=>'admin','configured'=>true,'ready'=>true,'driver'=>'google','revision'=>4,'from'=>'tenant@example.test','daily_limit'=>10]),'https://portal.example/portal-internal/mail/send'=>Http::response(['tenant'=>'example','role'=>'admin','status'=>'accepted','id'=>'provider-id'])]);$t=new CadenceEmailTransport;$c=$t->info();$this->assertSame('tenant@example.test',$c['from']);$t->send('message-uuid','r@example.test','Subject','Body',$c);
  Http::assertSent(function($r){if(!str_ends_with($r->url(),'/send'))return false;$stamp=$r->header('X-Discai-Time')[0];return $r['tenant']==='example'&&$r['revision']===4&&!isset($r['from'])&&hash_equals(hash_hmac('sha256',$stamp.'.portal-internal/mail/send.'.$r->body(),'fixture-secret'),$r->header('X-Discai-Signature')[0]);});
 }
 public function test_portal_outage_or_disconnected_mailbox_does_not_fall_back_to_smtp():void {
  Http::fake(['*'=>Http::response([],503)]);$this->assertFalse((new CadenceEmailTransport)->ready());
  Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();Http::fake(['*'=>Http::response(['tenant'=>'example','role'=>'admin','configured'=>true,'ready'=>false,'driver'=>'google','status'=>'disconnected'])]);$this->assertFalse((new CadenceEmailTransport)->ready());
 }
 public function test_unconfirmed_bridge_response_is_not_reported_as_accepted():void {
  Http::fake(['*'=>Http::response(['tenant'=>'example','role'=>'admin','status'=>'unknown'])]);$this->expectException(\RuntimeException::class);(new CadenceEmailTransport)->send('message-uuid','r@example.test','Subject','Body',['ready'=>true,'driver'=>'google','revision'=>4]);
 }
}
