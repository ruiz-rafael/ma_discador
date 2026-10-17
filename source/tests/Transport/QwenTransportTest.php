<?php
namespace Tests\Transport;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\AiLocalProvider;
class QwenTransportTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void {parent::setUp();config(['ai_local.trusted_origins'=>['http://127.0.0.1:19121']]);app(AiLocalProvider::class)->save(1,['text_provider'=>'qwen','endpoint'=>'http://127.0.0.1:19121','api_key'=>'fictional-private-server-key','model'=>'qwen3:8b','enabled'=>true,'daily_requests'=>100,'context_tokens'=>8192,'parallel_requests'=>1,'timeout_seconds'=>10,'revision'=>0]);}
 public function test_real_http_authentication_schema_and_usage():void {$r=app(AiLocalProvider::class)->text(1,'Instrução aprovada fictícia',[['role'=>'user','content'=>'Olá']]);$this->assertSame('reply',$r['action']);$this->assertSame(120,$r['usage']['input']);$this->assertSame(0,$r['estimated_usd']);}
 public function test_real_http_errors_and_timeout_do_not_return_an_answer():void {foreach(['rate-limit','unavailable','malformed','truncated','timeout']as $input){try{app(AiLocalProvider::class)->text(1,'Fixture',[['role'=>'user','content'=>$input]]);$this->fail($input);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(502,$e->getStatusCode());}}}
}
