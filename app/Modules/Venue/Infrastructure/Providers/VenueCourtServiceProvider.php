<?php

namespace App\Modules\Venue\Infrastructure\Providers;

use App\Modules\Venue\Presentation\Http\Controllers\VenueCourtController;
use App\Modules\Venue\Presentation\Http\Controllers\VenueCourtSettingsController;
use App\Modules\Venue\Presentation\Http\Controllers\VenueCourtPhotoController;
use App\Modules\Venue\Presentation\Http\Controllers\VenueCourtPublicController;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

final class VenueCourtServiceProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->routes(function (): void {
            Route::middleware('web')->group(function (): void {
                Route::get('/venues/{venue}/courts/{court}', VenueCourtPublicController::class)
                    ->name('venues.courts.show');
            });

            Route::middleware(['web', 'auth'])->group(function (): void {
                Route::get('/account/venues/{venue}/courts', [VenueCourtController::class, 'index'])
                    ->name('account.venues.courts.index');
                Route::post('/account/venues/{venue}/courts', [VenueCourtController::class, 'store'])
                    ->name('account.venues.courts.store');
                Route::put('/account/venues/{venue}/courts/{court}', [VenueCourtController::class, 'update'])
                    ->name('account.venues.courts.update');
                Route::put('/account/venues/{venue}/courts/{court}/settings', [VenueCourtSettingsController::class, 'updatePolicy'])
                    ->name('account.venues.courts.settings.update');
                Route::put('/account/venues/{venue}/courts/{court}/schedule', [VenueCourtSettingsController::class, 'updateSchedule'])
                    ->name('account.venues.courts.schedule.update');
                Route::post('/account/venues/{venue}/courts/{court}/prices', [VenueCourtSettingsController::class, 'storePrice'])
                    ->name('account.venues.courts.prices.store');
                Route::delete('/account/venues/{venue}/courts/{court}/prices/{price}', [VenueCourtSettingsController::class, 'destroyPrice'])
                    ->whereNumber('price')->name('account.venues.courts.prices.destroy');
                Route::post('/account/venues/{venue}/courts/{court}/primary', [VenueCourtController::class, 'makePrimary'])
                    ->name('account.venues.courts.primary');
                Route::delete('/account/venues/{venue}/courts/{court}', [VenueCourtController::class, 'destroy'])
                    ->name('account.venues.courts.destroy');

                Route::post('/account/venues/{venue}/courts/{court}/photos', [VenueCourtPhotoController::class, 'store'])
                    ->middleware('throttle:10,1')
                    ->name('account.venues.courts.photos.store');
                Route::patch('/account/venues/{venue}/courts/{court}/photos/{photo}/activate', [VenueCourtPhotoController::class, 'activate'])
                    ->middleware('throttle:20,1')
                    ->whereNumber('photo')
                    ->name('account.venues.courts.photos.activate');
                Route::delete('/account/venues/{venue}/courts/{court}/photos/{photo}', [VenueCourtPhotoController::class, 'destroy'])
                    ->middleware('throttle:20,1')
                    ->whereNumber('photo')
                    ->name('account.venues.courts.photos.destroy');
            });
        });
    }
}
