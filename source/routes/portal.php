<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PortalController as P;
Route::get('/',fn()=>redirect('/companies'));
Route::get('/login',[P::class,'loginPage'])->name('login');
Route::post('/login',[P::class,'login'])->middleware('throttle:5,1');
Route::get('/invite/{token}',[P::class,'invitation'])->middleware('throttle:30,1');
Route::post('/invite/{token}',[P::class,'accept'])->middleware('throttle:5,1');
Route::post('/portal-internal/redeem',[P::class,'redeem'])->middleware('throttle:120,1');
Route::post('/portal-internal/check',[P::class,'check'])->middleware('throttle:600,1');
Route::middleware(\App\Http\Middleware\PortalSession::class)->group(function(){
 Route::get('/companies',[P::class,'companies']);Route::post('/logout',[P::class,'logout']);Route::post('/password',[P::class,'password']);
 Route::get('/t/{slug}',[P::class,'launch']);Route::get('/authorize/{slug}',[P::class,'authorizeTenant']);
 Route::get('/administration',[P::class,'administration']);Route::post('/administration',[P::class,'createTenant']);
 Route::get('/administration/{id}',[P::class,'tenant']);Route::post('/administration/{id}',[P::class,'updateTenant']);
 Route::post('/administration/{id}/invitations',[P::class,'invite']);Route::post('/administration/{id}/invitations/{invitation}/revoke',[P::class,'revokeInvitation']);Route::post('/administration/{id}/members/{member}',[P::class,'membership']);
});

Route::middleware(\App\Http\Middleware\PortalSession::class)->group(function(){
 $c=\App\Http\Controllers\PortalNumbersController::class;
 Route::get('/companies/{tenant}/numbers',[$c,'index'])->whereNumber('tenant');
 Route::post('/companies/{tenant}/numbers',[$c,'store'])->whereNumber('tenant')->middleware('throttle:10,1');
 Route::get('/number-administration',[$c,'administration']);
 Route::get('/number-administration/{id}',[$c,'show'])->whereUuid('id');
 Route::post('/number-administration/{id}/{action}',[$c,'action'])->whereUuid('id')->whereIn('action',['quote','buy','adopt','reconcile','release','reject'])->middleware('throttle:10,1');
});

Route::match(['GET','POST'],'/number-hold',fn()=>response('<?xml version="1.0" encoding="UTF-8"?><Response><Reject reason="busy"/></Response>')->header('Content-Type','application/xml'));
