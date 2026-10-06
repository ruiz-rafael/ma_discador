<?php

use App\Http\Controllers\TwilioTrunkController;
use App\Http\Controllers\VoiceAudioController as A;
use App\Http\Controllers\VoiceCallingController;
use App\Http\Controllers\VoiceLabController as V;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'throttle:120,1'])->prefix('api/voice')->group(function () {
    Route::get('/provider/twilio', [TwilioTrunkController::class, 'show']);
    Route::get('/audio', [A::class, 'index']);
    Route::get('/audio/authorize', [A::class, 'authorize']);
    Route::post('/audio/sessions', [A::class, 'create'])->middleware('throttle:6,1,voice-audio-create:');
    Route::post('/audio/sessions/{id}/abandon', [A::class, 'abandon'])->whereUuid('id');
    Route::post('/audio/sessions/{id}/metrics', [A::class, 'metrics'])->whereUuid('id');
    Route::get('/', [V::class, 'index']);
    Route::get('/reference', [V::class, 'reference']);
    Route::get('/journeys', [V::class, 'journeys']);
    Route::put('/journeys/{id}/nodes/{node}', [\App\Http\Controllers\VoiceJourneyEditorController::class, 'node'])->whereNumber('id');
    Route::put('/journeys/{id}/layout', [\App\Http\Controllers\VoiceJourneyEditorController::class, 'layout'])->whereNumber('id');
    Route::post('/campaigns', [V::class, 'campaign']);
    Route::put('/campaigns/{id}', [V::class, 'campaign'])->whereNumber('id');
    Route::post('/campaigns/{id}/status', [V::class, 'transition'])->whereNumber('id');
    Route::post('/campaigns/{id}/activate', [V::class, 'activate'])->whereNumber('id');
    Route::post('/contacts', [V::class, 'contact']);
    Route::post('/contacts/{id}/stop', [V::class, 'stopContact'])->whereNumber('id');
    Route::post('/imports', [V::class, 'import']);
    Route::post('/calls', [V::class, 'start']);
    Route::post('/calls/{id}/finish', [V::class, 'finish'])->whereUuid('id');
    Route::post('/actions/{id}/simulate', [V::class, 'simulateAction'])->whereUuid('id');
});
Route::post('/internal/voice/audio/event', [A::class, 'event'])->middleware('throttle:120,1');

Route::middleware(['auth', 'throttle:120,1'])->prefix('api/voice/whatsapp')->group(function () {
    $c = WhatsAppController::class;
    Route::get('/', [$c, 'index']);
    Route::post('/senders', [$c, 'sender']);
    Route::put('/senders/{id}', [$c, 'senderSid'])->whereNumber('id');
    Route::post('/senders/{id}/sync', [$c, 'syncSender'])->whereNumber('id');
    Route::match(['GET', 'POST', 'DELETE'], '/senders/{id}/qr', [$c, 'qr'])->whereNumber('id')->middleware('throttle:30,1,wa-qr:');
    Route::post('/qr/templates', [$c, 'qrTemplate']);
    Route::post('/qr/preview', [$c, 'qrPreview']);
    Route::post('/qr/messages', [$c, 'qrSend'])->middleware('throttle:10,1,wa-qr-send:');
    Route::post('/followups/{id}/retry', [$c, 'retryFollowup'])->whereUuid('id');
    Route::post('/templates', [$c, 'template']);
    Route::post('/templates/{id}/publish', [$c, 'publish'])->whereUuid('id');
    Route::post('/templates/{id}/approval', [$c, 'approval'])->whereUuid('id');
    Route::post('/templates/{id}/reconcile', [$c, 'reconcileTemplate'])->whereUuid('id');
    Route::post('/messages', [$c, 'send'])->middleware('throttle:10,1,wa-send:');
    Route::post('/messages/{id}/reconcile', [$c, 'reconcileMessage'])->whereUuid('id');
});
Route::post('/callbacks/twilio/whatsapp/status/{id}', [WhatsAppWebhookController::class, 'status'])->whereUuid('id')->middleware('throttle:240,1,wa-hook:');
Route::post('/callbacks/twilio/whatsapp/inbound', [WhatsAppWebhookController::class, 'inbound'])->middleware('throttle:240,1,wa-hook:');

Route::middleware(['auth', 'throttle:120,1'])->prefix('api/voice/calling')->group(function () {
    $c = VoiceCallingController::class;
    Route::get('/', [$c, 'index']);
    Route::post('/calls', [$c, 'reserve'])->middleware('throttle:6,1,calling-create:');
    Route::post('/calls/{id}/cancel', [$c, 'cancel'])->whereUuid('id');
    Route::post('/calls/{id}/reconcile', [$c, 'reconcile'])->whereUuid('id')->middleware('throttle:6,1,voice-reconcile:');
});
Route::post('/internal/voice/calling/event', [VoiceCallingController::class, 'event'])->middleware('throttle:120,1,calling-event:');

Route::post('/callbacks/twilio/voice/dial', [\App\Http\Controllers\TwilioVoiceWebhookController::class, 'dial'])->middleware('throttle:120,1,voice-api:');
Route::post('/callbacks/twilio/voice/status/{id}', [\App\Http\Controllers\TwilioVoiceWebhookController::class, 'status'])->whereUuid('id')->middleware('throttle:240,1,voice-api:');
Route::post('/callbacks/twilio/voice/finish/{id}', [\App\Http\Controllers\TwilioVoiceWebhookController::class, 'finish'])->whereUuid('id')->middleware('throttle:240,1,voice-api:');

Route::middleware(['auth', 'throttle:120,1'])->prefix('api/voice/queues')->group(function () {
    $c = \App\Http\Controllers\VoiceQueueController::class;
    Route::get('/', [$c, 'index']);
    Route::post('/', [$c, 'configure']);
    Route::put('/{id}', [$c, 'configure'])->whereNumber('id');
    Route::post('/{id}/status', [$c, 'transition'])->whereNumber('id');
    Route::post('/{id}/populate', [$c, 'populate'])->whereNumber('id');
    Route::post('/{id}/claim', [$c, 'claim'])->whereNumber('id')->middleware('throttle:20,1,queue-claim:');
    Route::post('/items/{id}/priority', [$c, 'priority'])->whereNumber('id');
    Route::post('/presence', [$c, 'presence']);
    Route::post('/heartbeat', [$c, 'heartbeat']);
    Route::post('/assignments/{id}/finish', [$c, 'finish'])->whereUuid('id');
});

Route::middleware(['auth', 'throttle:120,1'])->prefix('api/voice/operations')->group(function () {
    $c = \App\Http\Controllers\VoiceOperationsController::class;
    foreach (['catalog','reports','export','queues'] as $action) Route::get('/'.$action, [$c,$action]);
    Route::get('/calls/{id}/history',[$c,'history'])->whereUuid('id');
    Route::post('/calls/{id}/disposition',[$c,'disposition'])->whereUuid('id');
    Route::post('/calls/{id}/costs',[$c,'costs'])->whereUuid('id')->middleware('throttle:10,1,voice-costs:');
    Route::post('/codes',[$c,'code']);
    Route::post('/origins/sync',[$c,'originsSync'])->middleware('throttle:5,1,voice-origins:');
    Route::put('/origins/{id}',[$c,'origin'])->whereNumber('id');
    Route::post('/lists',[$c,'createList']);
    Route::get('/lists/{id}/members',[$c,'members'])->whereNumber('id');
    Route::put('/members/{id}',[$c,'member'])->whereNumber('id');
    Route::post('/lists/{id}/preview',[$c,'preview'])->whereNumber('id');
    Route::post('/imports/{id}/commit',[$c,'commit'])->whereUuid('id');
    Route::get('/imports/{id}/errors',[$c,'importErrors'])->whereUuid('id');
    Route::put('/campaigns/{id}/policy',[$c,'policy'])->whereNumber('id');
    Route::post('/queues',[$c,'queue']);
    Route::put('/queues/{id}',[$c,'queue'])->whereNumber('id');
    Route::post('/queues/{id}/status',[$c,'queueStatus'])->whereNumber('id');
    Route::post('/queues/{id}/claim',[$c,'claim'])->whereNumber('id');
 Route::post('/wrapup/finish',[$c,'finishWrapup']);
 Route::post('/manual-reservations',[$c,'manual'])->middleware('throttle:6,1,manual-reserve:');
    Route::post('/reservations/{id}/cancel',[$c,'cancelReservation'])->whereUuid('id');
});

Route::middleware(['auth', 'throttle:120,1'])->prefix('api/voice/journey-reports')->group(function () {
    $c = \App\Http\Controllers\JourneyReportsController::class;
    Route::get('/', [$c, 'index']);
    Route::get('/details', [$c, 'details']);
    Route::get('/records/{kind}/{id}', [$c, 'record'])->whereIn('kind', ['calls','messages'])->whereUuid('id');
});

Route::middleware(['auth','throttle:60,1'])->prefix('api/voice/team')->group(function(){
 $c=\App\Http\Controllers\TeamController::class;
 Route::get('/',[$c,'index']);Route::post('/',[$c,'save']);Route::put('/{id}',[$c,'save'])->whereNumber('id');
});

Route::middleware(['auth','throttle:120,1'])->prefix('api/voice/inbound')->group(function(){
 $c=\App\Http\Controllers\InboundVoiceController::class;
 Route::get('/reports',[\App\Http\Controllers\InboundReportsController::class,'index']);Route::get('/reports/{id}',[\App\Http\Controllers\InboundReportsController::class,'show'])->whereUuid('id');
 Route::get('/',[$c,'index']);Route::post('/routes',[$c,'route']);Route::put('/routes/{id}',[$c,'route'])->whereNumber('id');
 Route::post('/routes/{id}/publish',[$c,'publish'])->whereNumber('id');Route::post('/token',[$c,'token']);Route::post('/device',[$c,'device']);
 foreach(['transfer','reconcile','disposition'] as $a)Route::post('/calls/{id}/'.$a,[$c,$a])->whereUuid('id');
});
Route::post('/callbacks/twilio/voice/inbound',[\App\Http\Controllers\InboundVoiceController::class,'callback'])->middleware('throttle:120,1,inbound:');
Route::post('/callbacks/twilio/voice/inbound/{kind}/{id?}',[\App\Http\Controllers\InboundVoiceController::class,'callback'])->whereIn('kind',['status','wait','offer','finish','transfer'])->whereUuid('id')->middleware('throttle:240,1,inbound-events:');

Route::middleware(['auth','throttle:120,1'])->prefix('api/voice/conversations')->group(function(){
 $c=\App\Http\Controllers\ConversationController::class;
 Route::get('/',[$c,'index']);Route::post('/routes',[$c,'route']);Route::get('/{id}',[$c,'show'])->whereUuid('id');
 foreach(['assign','read','send'] as $a)Route::post('/{id}/'.$a,[$c,$a])->whereUuid('id')->middleware('throttle:30,1,inbox-write:');
});

Route::middleware(['auth','throttle:60,1'])->prefix('api/voice/health')->group(function(){
 $m=\App\Http\Controllers\OperationalControlsController::class;
 Route::get('/costs',[$m,'costs']);Route::post('/costs/{channel}/{id}/sync',[$m,'syncCost'])->whereUuid('id');
 Route::get('/recovery',[$m,'recovery']);Route::get('/recovery/{id}/attempts',[$m,'attempts'])->whereUuid('id');
 Route::get('/retention',[$m,'retention']);Route::put('/retention',[$m,'saveRetention']);Route::post('/retention/archive',[$m,'archive']);Route::post('/retention/{id}/restore',[$m,'restore'])->whereUuid('id');

 Route::get('/diagnostics',[\App\Http\Controllers\OperationDiagnosticsController::class,'index']);$c=\App\Http\Controllers\OperationsHealthController::class;Route::get('/',[$c,'index']);Route::put('/policy',[$c,'policy']);Route::post('/calls/{id}/reconcile',[$c,'reconcile'])->whereUuid('id');
});

Route::middleware(['auth','throttle:60,1'])->prefix('api/voice/leads')->group(function(){Route::get('/',[\App\Http\Controllers\SocialLeadController::class,'index']);Route::put('/{id}',[\App\Http\Controllers\SocialLeadController::class,'update'])->whereUuid('id');});

Route::middleware(['auth','throttle:120,1'])->prefix('api/voice/recordings')->group(function(){
 $c=\App\Http\Controllers\VoiceRecordingController::class;
 Route::get('/queues/{id}',[$c,'queue'])->whereNumber('id');
 Route::get('/calls/{kind}/{id}',[$c,'state'])->whereIn('kind',['inbound','outbound'])->whereUuid('id');
 Route::post('/{sid}/control',[$c,'control'])->where('sid','RE[a-fA-F0-9]{32}');
 Route::get('/{sid}/audio',[$c,'audio'])->where('sid','RE[a-fA-F0-9]{32}');
});
Route::post('/callbacks/twilio/voice/recordings/{kind}/{id}',[\App\Http\Controllers\VoiceRecordingController::class,'callback'])->whereIn('kind',['inbound','outbound'])->whereUuid('id')->middleware('throttle:120,1,recording-events:');
