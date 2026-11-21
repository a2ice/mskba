<?php

use App\Presentation\UiStudio\UiStudioController;
use Illuminate\Support\Facades\Route;

if (app()->environment('ui-studio') && config('ui-studio.enabled')) {
    Route::get('/uikit/theme-assets/{slug}/{hash}/{kind}', [UiStudioController::class, 'asset'])
        ->where('slug', '[a-z][a-z0-9_-]{1,59}')->where('hash', '[a-f0-9]{64}')->where('kind', 'css|js')->name('ui-studio.asset');
    Route::get('/uikit/{ui_name?}', [UiStudioController::class, 'show'])
        ->where('ui_name', '[a-z][a-z0-9-]{1,79}')->name('ui-studio.show');
}
