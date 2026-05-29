<?php

use Illuminate\Support\Facades\Route;

use App\Modules\Identity\Presentation\Http\Controllers\AuthController;
use App\Modules\Identity\Presentation\Http\Controllers\AccountController;
use App\Presentation\Theming\ThemeResolver;

$themeResolver = app(ThemeResolver::class);

Route::get('/', function () use ($themeResolver) {
    return $themeResolver->page('welcome');
})->name('welcome');

Route::get('/login', function () use ($themeResolver) {
    return $themeResolver->page('auth.login');
})->name('login');

Route::get('/register', function () use ($themeResolver) {
    return $themeResolver->page('auth.register');
})->name('register');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('guest', 'throttle:5,1')
    ->name('auth.login');

Route::post('/auth/logout', [AuthController::class, 'logout'])
    ->name('auth.logout');

Route::prefix('venues')->group(function () use ($themeResolver) {
	Route::get('/', fn () => $themeResolver->page('venues.index'))->name('venues');
});

// Group routes for authenticated users
Route::middleware('auth')->group(function () use ($themeResolver) {
	// Dashboard route
	Route::get('/dashboard', fn () => $themeResolver->page('dashboard'))->name('dashboard');

	// Account routes
	Route::prefix('account')->group(function () {
		Route::get('/', [AccountController::class, 'index'])->name('account');
		Route::get('/contracts', [AccountController::class, 'contracts'])->name('account.contracts');
	});
});