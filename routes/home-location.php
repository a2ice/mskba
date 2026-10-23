<?php

use App\Modules\Location\Presentation\Http\Controllers\HomeLocationOptionsController;
use App\Modules\Portal\Presentation\Http\Controllers\HomeEventDiscoveryController;
use App\Modules\Portal\Presentation\Http\Controllers\HomeTodayEventMapController;
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

// Public, short-lived read model consumed by homepage venue map.
Route::get('/home/today-event-map', HomeTodayEventMapController::class)
    ->middleware('throttle:60,1')
    ->name('home.today-event-map');
