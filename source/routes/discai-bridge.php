<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DiscaiBridgeController as B;
Route::get('/discai/sso/start',[B::class,'start'])->middleware('throttle:20,1');
Route::get('/discai/sso/complete',[B::class,'complete'])->middleware('throttle:20,1');

Route::post('/discai-internal/numbers',[\App\Http\Controllers\ManagedNumbersController::class,'receive'])->middleware('throttle:30,1');

Route::post('/discai-internal/ai',[\App\Http\Controllers\ManagedAiController::class,'receive'])->middleware('throttle:30,1');

Route::post('/discai-internal/number-inventory',[\App\Http\Controllers\ManagedNumbersController::class,'inventory'])->middleware('throttle:30,1,number-inventory:');

Route::post('/discai-internal/calling',[\App\Http\Controllers\ManagedCallingController::class,'receive'])->middleware('throttle:120,1,platform-calling:');
