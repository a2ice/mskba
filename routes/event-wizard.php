<?php

use App\Modules\Event\Presentation\Http\Controllers\EventWizardController;
use Illuminate\Support\Facades\Route;

Route::prefix('events/create/wizard')
    ->middleware('auth')
    ->group(function () {
        Route::get('/', [EventWizardController::class, 'show'])
            ->name('events.wizard')
            ->defaults('breadcrumb', 'Новое мероприятие');
        Route::get('/teams', [EventWizardController::class, 'teams'])
            ->middleware('throttle:60,1')
            ->name('events.wizard.teams');
    });
