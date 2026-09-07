<?php

use App\Modules\Location\Presentation\Http\Controllers\HomeLocationOptionsController;
use Illuminate\Support\Facades\Route;

Route::get('/home/location-options', HomeLocationOptionsController::class)
    ->middleware('throttle:60,1')
    ->name('home.location-options');
