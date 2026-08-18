<?php

use App\Modules\Reaction\Presentation\Http\Controllers\SetReactionController;
use Illuminate\Support\Facades\Route;

Route::put('/reactions/{subjectType}/{subjectId}', SetReactionController::class)
    ->middleware(['auth', 'throttle:60,1'])
    ->whereNumber('subjectId')
    ->name('reactions.set');
