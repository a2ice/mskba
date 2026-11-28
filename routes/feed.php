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
Route::get('/feed/{contentItem:alias}/comments', [\App\Modules\Content\Presentation\Http\Controllers\ContentCommentController::class, 'index'])
    ->middleware('throttle:60,1')->name('news.comments.index');
Route::middleware(['auth', 'throttle:20,1'])->group(function (): void {
    Route::post('/feed/{contentItem:alias}/comments', [\App\Modules\Content\Presentation\Http\Controllers\ContentCommentController::class, 'store'])->name('news.comments.store');
    Route::patch('/feed/{contentItem:alias}/comments/{comment}', [\App\Modules\Content\Presentation\Http\Controllers\ContentCommentController::class, 'update'])->whereNumber('comment')->name('news.comments.update');
    Route::delete('/feed/{contentItem:alias}/comments/{comment}', [\App\Modules\Content\Presentation\Http\Controllers\ContentCommentController::class, 'destroy'])->whereNumber('comment')->name('news.comments.destroy');
});
Route::get('/feed/{contentItem:alias}', [NewsController::class, 'show'])
    ->name('news.show');

Route::getRoutes()->refreshNameLookups();
