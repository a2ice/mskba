<?php

use App\Modules\Content\Presentation\Http\Controllers\NewsController;
use Illuminate\Support\Facades\Route;

Route::get('/news', [NewsController::class, 'index'])
    ->name('legacy.news.index');
Route::get('/news/{contentItem:alias}', [NewsController::class, 'show'])
    ->name('legacy.news.show');

Route::get('/feed', [NewsController::class, 'index'])
    ->name('news.index')
    ->defaults('breadcrumb', 'Новости');
Route::get('/feed/{contentItem:alias}', [NewsController::class, 'show'])
    ->name('news.show');

Route::getRoutes()->refreshNameLookups();
