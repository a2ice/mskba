<?php

use App\Modules\Acquisition\Presentation\Http\Controllers\AcquisitionOnboardingController;
use Illuminate\Support\Facades\Route;

Route::prefix('join')->group(function (): void {
    Route::get('/success', [AcquisitionOnboardingController::class, 'success'])
        ->middleware('auth')
        ->name('acquisition.success');

    Route::post('/persona', [AcquisitionOnboardingController::class, 'updatePersona'])
        ->middleware('throttle:60,1')
        ->name('acquisition.persona');

    Route::post('/location', [AcquisitionOnboardingController::class, 'verifyLocation'])
        ->middleware('throttle:30,1')
        ->name('acquisition.location');

    Route::get('/{campaignCode?}', [AcquisitionOnboardingController::class, 'show'])
        ->where('campaignCode', '[A-Za-z0-9_-]{2,64}')
        ->name('acquisition.join');
});
