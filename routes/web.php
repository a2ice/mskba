<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\SiteController;
use App\Modules\Identity\Presentation\Http\Controllers\AuthFlowController;
use App\Modules\Identity\Presentation\Http\Controllers\AuthController;
use App\Modules\Identity\Presentation\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;


Route::get('/', [SiteController::class, 'index'])->name('home');

Route::get('/auth/login', [AccountController::class, 'login'])->name('login');
Route::get('/auth/register', [AccountController::class, 'register'])->name('register');

Route::post('/auth/login', [AuthController::class, 'login'])
	->middleware('throttle:5,1')
	->name('auth.login');

Route::post('/auth/register', [AuthController::class, 'register'])
	->middleware('throttle:5,1')
	->name('auth.register');

Route::post('/auth/restore', [AuthController::class, 'restore'])
	->middleware('throttle:5,1')
	->name('auth.restore');

Route::get('/auth/logout', [AuthController::class, 'logout'])
	->middleware('auth')
	->name('auth.logout');

// account middleware authorized users grouped by auth
Route::middleware('auth')->group(function () {
	Route::get('/account', [AccountController::class, 'index'])->name('account');
});

// admin panel grouped by auth and admin middleware
Route::prefix('admin')
	->middleware(['auth', 'admin'])
	->group(function () {
		Route::get('/', [AdminController::class, 'index'])->name('admin.index');
});










/* trashed routes starts here DONT TOUCH SO FAR */
Route::post('/auth/resolve-login', [AuthFlowController::class, 'resolveLogin'])
	->middleware('throttle:10,1')
	->name('auth.resolve-login');

Route::post('/auth/verify', [AuthFlowController::class, 'verify'])
	->middleware('throttle:10,1')
	->name('auth.verify');
/* trashed routes ends here */