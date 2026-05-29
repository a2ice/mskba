<?php

use App\Modules\Identity\Presentation\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('theme::pages.welcome');
});

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('guest', 'throttle:5,1')
    ->name('auth.login');

Route::post('/auth/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('auth.logout');
