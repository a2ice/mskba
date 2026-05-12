<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\SiteController;
use App\Modules\Identity\Presentation\Http\Controllers\AuthFlowController;

Route::get('/', [SiteController::class, 'index'])->name('home');

Route::post('/auth/resolve-login', [AuthFlowController::class, 'resolveLogin'])
	->middleware('throttle:10,1')
	->name('auth.resolve-login');

Route::post('/auth/verify', [AuthFlowController::class, 'verify'])
	->middleware('throttle:10,1')
	->name('auth.verify');