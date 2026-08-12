<?php

use App\Modules\Event\Presentation\Http\Controllers\GameLiveAudienceController;
use App\Modules\Event\Presentation\Http\Controllers\GameLiveController;
use App\Modules\Event\Presentation\Http\Controllers\GameLiveSnapshotController;
use Illuminate\Support\Facades\Route;

Route::prefix('events')->group(function (): void {
    Route::get('/{event}/games/{game}/live', GameLiveController::class)
        ->whereNumber('game')
        ->name('events.games.live');
    Route::get('/{event}/games/{game}/live/snapshot', GameLiveSnapshotController::class)
        ->whereNumber('game')
        ->middleware('throttle:120,1')
        ->name('events.games.live.snapshot');
    Route::post('/{event}/games/{game}/live/audience', GameLiveAudienceController::class)
        ->whereNumber('game')
        ->middleware('throttle:30,1')
        ->name('events.games.live.audience');
});
