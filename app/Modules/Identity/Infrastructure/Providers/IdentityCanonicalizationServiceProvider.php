<?php

namespace App\Modules\Identity\Infrastructure\Providers;

use App\Modules\Admin\Presentation\Http\Controllers\AdminUserDuplicatesController;
use App\Modules\Contact\Domain\Events\UserContactConfirmed;
use App\Modules\Identity\Application\Listeners\ScanUserDuplicatesAfterContactConfirmed;
use App\Modules\Identity\Application\Services\UserDuplicateDetector;
use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Presentation\Http\Controllers\UserDuplicateController;
use App\Modules\Telegram\Presentation\Http\Controllers\LinkTelegramIdentityController;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class IdentityCanonicalizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(UserContactConfirmed::class, ScanUserDuplicatesAfterContactConfirmed::class);

        Profile::saved(function (Profile $profile): void {
            $user = $profile->user;

            if ($user !== null) {
                app(UserDuplicateDetector::class)->scan($user);
            }
        });

        Route::middleware(['web', 'auth'])
            ->prefix('account')
            ->group(function (): void {
                Route::get('/telegram', fn () => ThemeResolver::page('account.telegram'))
                    ->name('account.telegram')
                    ->defaults('breadcrumb', 'Telegram');
                Route::post('/telegram/link', LinkTelegramIdentityController::class)
                    ->middleware('throttle:10,1')
                    ->name('account.telegram.link');

                Route::get('/duplicate-accounts/{userDuplicate}', [UserDuplicateController::class, 'show'])
                    ->name('account.user-duplicates.show');
                Route::post('/duplicate-accounts/{userDuplicate}/merge', [UserDuplicateController::class, 'merge'])
                    ->middleware('throttle:5,1')
                    ->name('account.user-duplicates.merge');
            });

        Route::middleware(['web', 'auth', 'can:access-admin-panel'])
            ->prefix('admin/users/duplicates')
            ->group(function (): void {
                Route::get('/', [AdminUserDuplicatesController::class, 'index'])
                    ->name('admin.users.duplicates')
                    ->defaults('breadcrumb', 'Дубли пользователей');
                Route::post('/{userDuplicate}/merge', [AdminUserDuplicatesController::class, 'merge'])
                    ->middleware('can:manage-users-as-superadmin')
                    ->name('admin.users.duplicates.merge');
                Route::post('/{userDuplicate}/reject', [AdminUserDuplicatesController::class, 'reject'])
                    ->middleware('can:manage-users-as-superadmin')
                    ->name('admin.users.duplicates.reject');
            });
    }
}
