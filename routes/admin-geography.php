<?php

use App\Modules\Admin\Presentation\Http\Controllers\AdminGeographyController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/geography')
    ->middleware('auth', 'can:access-admin-panel')
    ->group(function (): void {
        Route::get('/', [AdminGeographyController::class, 'index'])
            ->name('admin.geography')
            ->defaults('breadcrumb', 'Города и районы');

        Route::post('/cities', [AdminGeographyController::class, 'storeCity'])
            ->name('admin.geography.cities.store');
        Route::put('/cities/{city}', [AdminGeographyController::class, 'updateCity'])
            ->whereNumber('city')
            ->name('admin.geography.cities.update');
        Route::delete('/cities/{city}', [AdminGeographyController::class, 'destroyCity'])
            ->whereNumber('city')
            ->name('admin.geography.cities.destroy');

        Route::post('/districts', [AdminGeographyController::class, 'storeDistrict'])
            ->name('admin.geography.districts.store');
        Route::put('/districts/{district}', [AdminGeographyController::class, 'updateDistrict'])
            ->whereNumber('district')
            ->name('admin.geography.districts.update');
        Route::delete('/districts/{district}', [AdminGeographyController::class, 'destroyDistrict'])
            ->whereNumber('district')
            ->name('admin.geography.districts.destroy');
    });
