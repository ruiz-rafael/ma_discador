<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DiscaiBridgeController as B;
Route::get('/discai/sso/start',[B::class,'start'])->middleware('throttle:20,1');
Route::get('/discai/sso/complete',[B::class,'complete'])->middleware('throttle:20,1');
