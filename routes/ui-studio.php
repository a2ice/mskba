<?php

use App\Presentation\UiStudio\UiStudioController;
use Illuminate\Support\Facades\Route;

if (app()->environment('ui-studio') && config('ui-studio.enabled')) {
    // Isolated session: no portal web middleware, auth, DB or shared cookies.
    // The standalone runtime must use SESSION_DRIVER=file, a dedicated APP_KEY,
    // and its own filesystem storage. Never borrow portal secrets.
    Route::middleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])->group(function (): void {
        Route::get('/uikit/bridge', [UiStudioController::class, 'bridge'])->name('ui-studio.bridge');
        Route::post('/uikit/session', [UiStudioController::class, 'exchange'])
            ->middleware('throttle:10,1')->name('ui-studio.exchange');
        Route::post('/uikit/reviews', [UiStudioController::class, 'accept'])
            ->middleware('throttle:15,1')->name('ui-studio.review');
        Route::get('/uikit/theme-assets/{slug}/{hash}/{kind}', [UiStudioController::class, 'asset'])
            ->where('slug', '[a-z][a-z0-9_-]{1,59}')->where('hash', '[a-f0-9]{64}')->where('kind', 'css|js')->name('ui-studio.asset');
        Route::get('/uikit/{ui_name?}', [UiStudioController::class, 'show'])
            ->where('ui_name', '[a-z][a-z0-9-]{1,79}')->name('ui-studio.show');
    });
}
