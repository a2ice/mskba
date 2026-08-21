<?php

namespace App\Modules\Vk\Infrastructure\Providers;

use App\Modules\Vk\Presentation\Http\Controllers\StartVkAuthenticationController;
use App\Modules\Vk\Presentation\Http\Controllers\VkCallbackController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class VkServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'throttle:10,1'])->group(function (): void {
            Route::get('/auth/vk', StartVkAuthenticationController::class)->name('auth.vk.start');
            Route::get('/auth/vk/callback', VkCallbackController::class)->name('auth.vk.callback');
        });

        Route::middleware(['web', 'auth'])->prefix('account')->group(function (): void {
            Route::get('/vk', fn () => redirect()->route('account.contacts'))
                ->name('account.vk')
                ->defaults('breadcrumb', 'VK ID');
            Route::get('/vk/link', StartVkAuthenticationController::class)
                ->middleware('throttle:10,1')
                ->name('account.vk.link');
        });
    }
}
