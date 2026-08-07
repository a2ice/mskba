<?php

use App\Modules\Event\Presentation\Http\Controllers\GameLiveController;
use Illuminate\Support\Facades\Route;

Route::prefix('events')->group(function (): void {
    Route::get('/{event}/games/{game}/live', GameLiveController::class)
        ->whereNumber('game')
        ->name('events.games.live');
});
