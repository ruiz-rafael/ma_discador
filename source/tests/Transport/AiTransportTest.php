<?php
namespace Tests\Transport;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\AiProvider;
class AiTransportTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void {parent::setUp();config(['ai_test.url'=>'http://127.0.0.1:19097/openai/v1']);app(AiProvider::class)->save(1,['api_key'=>'sk-fictional-transport-only','enabled'=>true,'daily_requests'=>100]);}
 public function test_actual_http_structured_response_and_sdp_transport():void {$p=app(AiProvider::class);$r=$p->text(1,'Approved fixture instructions',[['role'=>'user','content'=>'Olá']]);$this->assertSame('reply',$r['action']);$this->assertSame(120,$r['usage']['input']);$voice=$p->voice(1,"v=0\r\ns=offer\r\n",['type'=>'realtime','model'=>AiProvider::VOICE_MODEL]);$this->assertSame('rtc_fixture',$voice['call_id']);$this->assertTrue($p->hangup(1,$voice['call_id']));}
 public function test_actual_http_429_html_500_and_malformed_reply_fail_closed():void {$p=app(AiProvider::class);foreach(['provider-rate-limit','provider-html-error','provider-malformed']as $input){try{$p->text(1,'Approved fixture instructions',[['role'=>'user','content'=>$input]]);$this->fail('Must reject provider failure');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(502,$e->getStatusCode());}}}
 public function test_connection_refused_is_reported_without_retry():void {config(['ai_test.url'=>'http://127.0.0.1:19099/v1']);$this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);app(AiProvider::class)->text(1,'Test',[['role'=>'user','content'=>'Olá']]);}
}
