<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AiAgentsController as A;
Route::middleware(['auth','throttle:60,1'])->prefix('api/ai')->group(function(){
 Route::get('/supervision',[\App\Http\Controllers\AiSupervisionController::class,'index']);
 Route::get('/supervision/sessions/{id}',[\App\Http\Controllers\AiSupervisionController::class,'show'])->whereUuid('id');
 Route::get('/agents',[A::class,'index']);Route::post('/agents',[A::class,'save']);Route::put('/connection',[A::class,'connection']);
 Route::put('/agents/{id}',[A::class,'save'])->whereUuid('id');
 foreach(['publish','pause','start','voice','binding','sip']as $action)Route::post('/agents/{id}/'.$action,[A::class,$action])->whereUuid('id')->middleware('throttle:10,1,ai-'.$action.':');
 Route::get('/sessions/{id}',[A::class,'session'])->whereUuid('id');Route::post('/sessions/{id}/turn',[A::class,'turn'])->whereUuid('id')->middleware('throttle:20,1,ai-turn:');Route::post('/sessions/{id}/close',[A::class,'close'])->whereUuid('id');
});

Route::post('/callbacks/ai/sip/{id}',[\App\Http\Controllers\AiSipController::class,'incoming'])->whereUuid('id')->middleware('throttle:120,1,ai-sip:');
Route::post('/internal/ai/voice/poll',[\App\Http\Controllers\AiSipController::class,'poll'])->middleware('throttle:120,1,ai-worker:');
Route::post('/internal/ai/voice/{id}/event',[\App\Http\Controllers\AiSipController::class,'event'])->whereUuid('id')->middleware('throttle:240,1,ai-worker-event:');
