<?php
use Illuminate\Support\Facades\{Route,DB};
use App\Http\Controllers\{IntegrationController,PublicApiController,VoiceOperationsController,VoiceQueueController,VoiceCallingController,InboundVoiceController};
Route::middleware(['auth','throttle:60,1'])->prefix('api/voice/integrations')->group(function(){Route::post('/{id}/reference-session',[IntegrationController::class,'referenceSession'])->whereUuid('id');Route::get('/',[IntegrationController::class,'index']);Route::post('/',[IntegrationController::class,'create']);Route::post('/{id}/revoke',[IntegrationController::class,'revoke'])->whereUuid('id');Route::post('/deliveries/{id}/retry',[IntegrationController::class,'retry'])->whereUuid('id');});
Route::prefix('api/v1')->middleware('throttle:240,1')->group(function(){
 Route::post('/journeys/{id}/entries',[PublicApiController::class,'entry'])->whereNumber('id')->middleware(\App\Http\Middleware\PublicIntegration::class.':journeys:write');
 foreach([['calls/inbound','inbound','calls:read'],['calls/inbound/{id}','inboundDetail','calls:read'],['conversations','conversations','conversations:read'],['conversations/{id}/messages','messages','conversations:read'],['reports/costs','costs','costs:read']] as [$path,$action,$scope])Route::get($path,[\App\Http\Controllers\PublicReadController::class,$action])->whereUuid('id')->middleware(\App\Http\Middleware\PublicIntegration::class.':'.$scope);

 foreach([['GET','lists','lists','lists:read'],['POST','contacts/import','import','lists:write'],['GET','calls','calls','calls:read'],['GET','reports/journeys','reports','reports:read'],['GET','events','events','events:read'],['POST','embed-sessions','session','voice:embed'],['POST','leads','lead','leads:write']] as [$method,$path,$action,$scope])Route::match([$method],$path,[PublicApiController::class,$action])->middleware(\App\Http\Middleware\PublicIntegration::class.':'.$scope);
});
Route::get('/integrations/openapi.json',fn()=>response()->file(resource_path('contracts/openapi.json'),['Content-Type'=>'application/json']));
Route::get('/integrations/guide',fn()=>view('integration-guide'));
Route::get('/integrations/reference',fn()=>view('integration-reference'));
Route::get('/embed',function(\Illuminate\Http\Request $r){$origin=$r->query('origin');abort_unless(is_string($origin)&&DB::table('ma_integrations')->where('active',true)->where('embed_origin',$origin)->exists(),403);return response()->view('embed',['origin'=>$origin])->header('Content-Security-Policy',"frame-ancestors ".$origin)->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer');});
Route::middleware([\App\Http\Middleware\EmbeddedAgent::class,'throttle:120,1'])->prefix('embed/api/voice')->group(function(){
 $recording=\App\Http\Controllers\VoiceRecordingController::class;Route::get('/recordings/calls/{kind}/{id}',[$recording,'state'])->whereIn('kind',['inbound','outbound'])->whereUuid('id');Route::post('/recordings/{sid}/control',[$recording,'control'])->where('sid','RE[a-fA-F0-9]{32}');Route::get('/recordings/{sid}/audio',[$recording,'audio'])->where('sid','RE[a-fA-F0-9]{32}');
 Route::get('/operations/catalog',[VoiceOperationsController::class,'catalog']);Route::get('/operations/queues',[VoiceOperationsController::class,'queues']);
 Route::post('/queues/presence',[VoiceQueueController::class,'presence']);Route::post('/queues/heartbeat',[VoiceQueueController::class,'heartbeat']);
 Route::post('/operations/queues/{id}/claim',[VoiceOperationsController::class,'claim'])->whereNumber('id');
 Route::post('/operations/wrapup/finish',[VoiceOperationsController::class,'finishWrapup']);
 Route::post('/operations/manual-reservations',[VoiceOperationsController::class,'manual'])->middleware('throttle:6,1,manual-reserve:');Route::post('/operations/reservations/{id}/cancel',[VoiceOperationsController::class,'cancelReservation'])->whereUuid('id');Route::post('/operations/calls/{id}/disposition',[VoiceOperationsController::class,'disposition'])->whereUuid('id');
 Route::get('/calling',[VoiceCallingController::class,'index']);Route::post('/calling/calls',[VoiceCallingController::class,'reserve']);Route::post('/calling/calls/{id}/cancel',[VoiceCallingController::class,'cancel'])->whereUuid('id');Route::post('/calling/calls/{id}/reconcile',[VoiceCallingController::class,'reconcile'])->whereUuid('id');
 Route::get('/inbound',[InboundVoiceController::class,'index']);Route::post('/inbound/token',[InboundVoiceController::class,'token']);Route::post('/inbound/device',[InboundVoiceController::class,'device']);foreach(['transfer','reconcile','disposition'] as $action)Route::post('/inbound/calls/{id}/'.$action,[InboundVoiceController::class,$action])->whereUuid('id');
});
