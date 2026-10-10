<?php

use App\Modules\Admin\Presentation\Http\Controllers\AdminContentController;
use App\Modules\Content\Presentation\Http\Controllers\FaqController;
use App\Modules\Content\Presentation\Http\Controllers\FaqHelpController;
use App\Modules\Content\Presentation\Http\Controllers\SupportQuestionController;
use Illuminate\Support\Facades\Route;

Route::prefix('faq')->group(function (): void {
    Route::get('/help-content', FaqHelpController::class)->middleware('throttle:60,1')->name('faq.help-content');
    Route::post('/questions', SupportQuestionController::class)->middleware(['auth', 'throttle:5,10'])->name('faq.questions.store');
    Route::get('/', [FaqController::class, 'index'])
        ->name('faq.index')
        ->defaults('breadcrumb', 'FAQ');
    Route::get('/search', [FaqController::class, 'search'])
        ->middleware('throttle:60,1')
        ->name('faq.search');
    Route::get('/creation/{topic}', [FaqController::class, 'creation'])
        ->where('topic', 'venues|events|teams|tournaments|coordination|sections')
        ->name('faq.creation');
    Route::get('/welcome', [FaqController::class, 'welcome'])
        ->name('faq.welcome')
        ->defaults('breadcrumb', 'Первые шаги');
    Route::get('/{contentItem:alias}', [FaqController::class, 'show'])
        ->name('faq.show');
});

Route::prefix('admin/content')
    ->middleware('auth', 'can:manage-content')
    ->group(function (): void {
        Route::post('/{contentItem:alias}/images', [AdminContentController::class, 'storeInlineImage'])
            ->name('admin.content.images.store');
        Route::delete('/{contentItem:alias}/images/{media}', [AdminContentController::class, 'destroyInlineImage'])
            ->whereNumber('media')
            ->name('admin.content.images.destroy');
    });
