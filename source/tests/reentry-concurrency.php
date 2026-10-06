<?php
require __DIR__.'/../vendor/autoload.php';$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Artisan,Http};
use App\Services\CadenceReentry;
if(!app()->environment('testing')||config('database.default')!=='pgsql'||config('database.connections.pgsql.database')!=='milestones_qa')throw new RuntimeException('Isolated QA PostgreSQL required');
Http::preventStrayRequests();
if(($argv[1]??'')==='worker'){echo json_encode(app(CadenceReentry::class)->event(1,1,1,$argv[2]));exit;}
function ensure($ok,$why){if(!$ok)throw new RuntimeException($why);}
Artisan::call('migrate:fresh',['--force'=>true]);
DB::table('voice_contacts')->insert(['id'=>1,'workspace_id'=>1,'name'=>'Synthetic contact','phone'=>'+5511999990001','original_phone'=>'+5511999990001','source'=>'isolated QA','consent'=>true,'consent_evidence'=>'Internal fixture']);
DB::table('voice_campaigns')->insert(['id'=>1,'workspace_id'=>1,'name'=>'Concurrent reentry QA','status'=>'testing','settings'=>json_encode(['max_attempts'=>5,'reentry'=>array_replace(CadenceReentry::DEFAULTS,['mode'=>'event'])])]);DB::table('voice_members')->insert(['campaign_id'=>1,'contact_id'=>1]);
$report=['requests'=>0,'workers'=>4,'provider_requests'=>0];
foreach(['first','second']as $wave){
 $results=[];foreach(array_chunk(range(1,20),4)as $batch){$jobs=[];foreach($batch as $i){$p=[];$proc=proc_open([PHP_BINARY,__FILE__,'worker',$wave.':'.($i<=10?'duplicate':$i)],[1=>['pipe','w'],2=>['pipe','w']],$p);$jobs[]=[$proc,$p];}foreach($jobs as [$proc,$p]){$out=stream_get_contents($p[1]);$err=stream_get_contents($p[2]);fclose($p[1]);fclose($p[2]);ensure(proc_close($proc)===0,'Worker failure '.$err);$results[]=json_decode($out,true,flags:JSON_THROW_ON_ERROR);}}
 ensure(DB::table('voice_cadence_runs')->where('status','active')->count()===1,'Multiple active executions');$r=DB::table('voice_cadence_runs')->where('status','active')->first();$report[$wave]=['number'=>$r->number,'active'=>1,'unique_admissions'=>count(array_filter($results,fn($x)=>($x['eligible']??false)&&!($x['duplicate']??false)))];ensure($report[$wave]['unique_admissions']===1,'Expected one new admission');$report['requests']+=count($results);
 if($wave==='first')app(CadenceReentry::class)->finish($r,'attempts_exhausted');
}
ensure(DB::table('voice_cadence_runs')->count()===2,'History lost or duplicated');ensure(DB::table('voice_outbound_calls')->count()===0&&DB::table('wa_messages')->count()===0,'Unexpected call/message');Http::assertNothingSent();$report['passed']=true;echo json_encode($report,JSON_PRETTY_PRINT).PHP_EOL;
