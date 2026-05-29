<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Identity\Presentation\Http\Controllers\AuthController;
use App\Modules\Identity\Presentation\Http\Controllers\AccountController;

Route::get('/', function () {
    return view('theme::pages.welcome');
})->name('welcome');

Route::get('/login', function () {
    return view('theme::pages.auth.login');
})->name('login');

Route::get('/register', function () {
    return view('theme::pages.auth.register');
})->name('register');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('guest', 'throttle:5,1')
    ->name('auth.login');

Route::post('/auth/logout', [AuthController::class, 'logout'])
    ->name('auth.logout');


// Group routes for authenticated users
Route::middleware('auth')->group(function () {
	// Dashboard route
	Route::get('/dashboard', function () {
		return view('theme::pages.dashboard');
	})->name('dashboard');

	// Profile route
	Route::get('/account', [AccountController::class, 'index'])->name('account');
});