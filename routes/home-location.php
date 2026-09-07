<?php

use App\Modules\Location\Presentation\Http\Controllers\HomeLocationOptionsController;
use App\Modules\Portal\Presentation\Http\Controllers\HomeEventDiscoveryController;
use App\Modules\Portal\Presentation\Http\Controllers\HomeVenueRadiusSearchController;
use Illuminate\Support\Facades\Route;

Route::get('/home/location-options', HomeLocationOptionsController::class)
    ->middleware('throttle:60,1')
    ->name('home.location-options');

Route::get('/home/event-discovery', HomeEventDiscoveryController::class)
    ->middleware('throttle:60,1')
    ->name('home.event-discovery');

Route::get('/home/venue-radius-search/{latitude}/{longitude}/{radiusKm}', HomeVenueRadiusSearchController::class)
    ->middleware('throttle:60,1')
    ->name('home.venue-radius-search');
