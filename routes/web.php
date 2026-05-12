<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\SiteController;

Route::get('/', [SiteController::class, 'index'])->name('home');

Route::match(['get', 'post'], '/login', function () {
	return redirect()->route('home');
})->name('login');

Route::match(['get', 'post'], '/register', function () {
	return redirect()->route('home');
})->name('register');

Route::get('/account', function () {
	return redirect()->route('home');
})->name('account');
