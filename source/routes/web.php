<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MarketingController as M;
use App\Http\Controllers\CrmCatalogController as C;
require __DIR__.'/voice.php';
require __DIR__.'/lists.php';
require __DIR__.'/integrations.php';
Route::get('/',fn()=>view('app'))->name('login');
Route::post('/login',[M::class,'login'])->middleware('throttle:5,1');
Route::post('/events',[M::class,'event'])->middleware('throttle:120,1');
Route::post('/crm/catalog/sync',[C::class,'sync'])->middleware('throttle:30,1');
Route::middleware('auth')->prefix('api')->group(function(){
 Route::get('/crm/catalog',[C::class,'index']);Route::post('/crm/catalog',[C::class,'store']);
 Route::post('/node-config/validate',[C::class,'validateNode']);Route::post('/graph/validate',[C::class,'validateGraph']);
 Route::post('/logout',[M::class,'logout']);Route::get('/bootstrap',[M::class,'bootstrap']);
 Route::post('/audiences',[M::class,'audience']);Route::post('/contacts',[M::class,'contact']);
 Route::post('/journeys',[M::class,'create']);Route::get('/journeys/{journey}',[M::class,'show']);Route::put('/journeys/{journey}/graph',[M::class,'save']);
 Route::post('/journeys/{journey}/publish',[M::class,'publish']);Route::post('/journeys/{journey}/pause',[M::class,'pause']);Route::post('/journeys/{journey}/resume',[M::class,'resume']);Route::delete('/journeys/{journey}',[M::class,'destroy']);Route::post('/journeys/{journey}/enroll',[M::class,'enroll']);
});
