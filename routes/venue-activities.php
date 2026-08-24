<?php

use App\Modules\Venue\Presentation\Http\Controllers\VenueActivityController;
use Illuminate\Support\Facades\Route;

Route::get('/venues/{venue}/activities', VenueActivityController::class)
    ->middleware('throttle:120,1')
    ->name('venues.activities');
