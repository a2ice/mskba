<?php

use App\Modules\Content\Presentation\Http\Controllers\NewsController;
use Illuminate\Support\Facades\Route;

Route::prefix('feed')->group(function () {
    Route::get('/', [NewsController::class, 'index'])
        ->name('feed.index')
        ->defaults('breadcrumb', 'Новости');
    Route::get('/{contentItem:alias}', [NewsController::class, 'show'])
        ->name('feed.show');
});
