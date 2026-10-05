<?php
use App\Http\Controllers\ListManagerController as L;
use Illuminate\Support\Facades\Route;
Route::middleware(['auth', 'throttle:120,1'])->prefix('api/lists')->group(function () {
    Route::get('/', [L::class, 'index']); Route::post('/', [L::class, 'create']);
    Route::prefix('{kind}/{id}')->where(['kind' => 'automation|voice', 'id' => '[0-9]+'])->group(function () {
        Route::get('/', [L::class, 'show']); Route::put('/', [L::class, 'configure']);
        Route::post('/segment-preview',[L::class,'segmentPreview']);
        Route::get('/available-contacts', [L::class, 'candidates']); Route::post('/existing-contacts', [L::class, 'attach']);
        Route::post('/contacts', [L::class, 'contact']);
        Route::delete('/contacts/{contact}', [L::class, 'remove'])->whereNumber('contact');
        Route::post('/contacts/{contact}/restore', [L::class, 'restore'])->whereNumber('contact');
        Route::post('/csv/inspect', [L::class, 'inspect']); Route::post('/csv/preview', [L::class, 'preview']);
        Route::post('/imports/{batch}/commit', [L::class, 'commit'])->whereUuid('batch');
        Route::post('/webhook-preview', [L::class, 'webhookPreview']);
        Route::post('/webhooks', [L::class, 'webhook']); Route::put('/webhooks/{hook}', [L::class, 'webhook'])->whereUuid('hook');
    });
});
Route::post('/hooks/lists/{hook}', [L::class, 'receive'])->whereUuid('hook')->middleware('throttle:60,1');
