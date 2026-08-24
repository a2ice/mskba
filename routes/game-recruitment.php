<?php

use App\Modules\Event\Presentation\Http\Controllers\StandaloneGameAdmissionController;
use App\Modules\Event\Presentation\Http\Controllers\StandaloneGameCandidateSearchController;
use App\Modules\Event\Presentation\Http\Controllers\StandaloneGameFormationController;
use App\Modules\Event\Presentation\Http\Controllers\StandaloneGameQrJoinController;
use Illuminate\Support\Facades\Route;

Route::prefix('events/{event}/games/{game}/recruitment')
    ->whereNumber('game')
    ->name('events.games.recruitment.')
    ->group(function (): void {
        Route::get('/panel', [StandaloneGameAdmissionController::class, 'panel'])->name('panel');
        Route::get('/join', StandaloneGameQrJoinController::class)->name('join');

        Route::middleware('auth')->group(function (): void {
            Route::get('/candidates', StandaloneGameCandidateSearchController::class)->name('candidates');
            Route::post('/apply', [StandaloneGameAdmissionController::class, 'apply'])->name('apply');
            Route::post('/invite', [StandaloneGameAdmissionController::class, 'invite'])->name('invite');
            Route::post('/admissions/{admission}/respond', [StandaloneGameAdmissionController::class, 'respond'])
                ->whereNumber('admission')->name('respond');
            Route::delete('/admissions/{admission}', [StandaloneGameAdmissionController::class, 'revoke'])
                ->whereNumber('admission')->name('revoke');

            Route::post('/formation/preview', [StandaloneGameFormationController::class, 'preview'])->name('formation.preview');
            Route::post('/formation/apply', [StandaloneGameFormationController::class, 'apply'])->name('formation.apply');
            Route::post('/teams/confirm', [StandaloneGameFormationController::class, 'confirmTeams'])->name('teams.confirm');
            Route::delete('/sides/confirmation', [StandaloneGameFormationController::class, 'unconfirm'])->name('unconfirm');
            Route::patch('/applications', [StandaloneGameFormationController::class, 'applications'])->name('applications');
            Route::put('/configuration', [StandaloneGameFormationController::class, 'configuration'])->name('configuration');
        });
    });
