<?php

use App\Modules\Event\Presentation\Http\Controllers\EventWizardController;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Infrastructure\Http\Middleware\EnsureOperationalPermission;
use Illuminate\Support\Facades\Route;

Route::prefix('events/create/wizard')
    ->middleware([
        'auth',
        EnsureOperationalPermission::class.':'.UserOperationalPermissionEnum::CREATE_EVENT->value,
    ])
    ->group(function () {
        Route::get('/', [EventWizardController::class, 'show'])
            ->name('events.wizard')
            ->defaults('breadcrumb', 'Новое мероприятие');
        Route::get('/teams', [EventWizardController::class, 'teams'])
            ->middleware('throttle:60,1')
            ->name('events.wizard.teams');
        Route::get('/venues', [EventWizardController::class, 'venues'])
            ->middleware('throttle:120,1')
            ->name('events.wizard.venues');
    });
