<?php

use Illuminate\Support\Facades\Route;

use App\Presentation\Theming\ThemeResolver;

use App\Modules\Identity\Presentation\Http\Controllers\AuthController;
use App\Modules\Identity\Presentation\Http\Controllers\AccountController;
use App\Modules\Venue\Presentation\Http\Controllers\VenueController;

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
	Route::get('/', [VenueController::class, 'index'])->name('venues');
	Route::get('/{alias}', [VenueController::class, 'show'])->name('venues.show');
	Route::get('/{alias}/edit', [VenueController::class, 'edit'])->name('venues.edit');
});

// Group routes for authenticated users
Route::middleware('auth')->group(function () use ($themeResolver) {
	// Dashboard route
	Route::get('/dashboard', fn () => $themeResolver->page('dashboard'))->name('dashboard');

	// Account routes
	Route::prefix('account')->group(function () {
		Route::get('/', [AccountController::class, 'index'])->name('account');
		Route::get('/settings', [AccountController::class, 'settings'])->name('account.settings');
		Route::get('/contacts', [AccountController::class, 'contacts'])->name('account.contacts');
		Route::get('/contracts', [AccountController::class, 'contracts'])->name('account.contracts');
		Route::get('/contracts/{number}', [AccountController::class, 'contract'])->name('account.contracts.show');
		Route::get('/venues', [AccountController::class, 'venues'])->name('account.venues');
		Route::get('/venues/{alias}', [AccountController::class, 'showVenue'])->name('account.venues.show');
		Route::get('/venues/{alias}/edit', [AccountController::class, 'editVenue'])->name('account.venues.edit');
	});
});