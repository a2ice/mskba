<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthFlowController;
use App\Http\Controllers\SiteController;

Route::get('/', [SiteController::class, 'index'])->name('home');

Route::post('/auth/resolve-login', [AuthFlowController::class, 'resolveLogin'])
	->middleware('throttle:20,1')
	->name('auth.resolve-login');