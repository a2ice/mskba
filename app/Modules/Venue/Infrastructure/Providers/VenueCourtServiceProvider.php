<?php

namespace App\Modules\Venue\Infrastructure\Providers;

use App\Modules\Venue\Presentation\Http\Controllers\VenueCourtController;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

final class VenueCourtServiceProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Route::middleware(['web', 'auth'])->group(function (): void {
            Route::get('/account/venues/{venue}/courts', [VenueCourtController::class, 'index'])
                ->name('account.venues.courts.index');
            Route::post('/account/venues/{venue}/courts', [VenueCourtController::class, 'store'])
                ->name('account.venues.courts.store');
            Route::put('/account/venues/{venue}/courts/{court}', [VenueCourtController::class, 'update'])
                ->name('account.venues.courts.update');
            Route::post('/account/venues/{venue}/courts/{court}/primary', [VenueCourtController::class, 'makePrimary'])
                ->name('account.venues.courts.primary');
            Route::delete('/account/venues/{venue}/courts/{court}', [VenueCourtController::class, 'destroy'])
                ->name('account.venues.courts.destroy');
        });
    }
}
