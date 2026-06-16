<?php

use App\Modules\Audit\Presentation\Http\Controllers\AdminAuditController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminContentController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminEventsController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminSettingsController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminTeamsController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminVenuesController;
use App\Modules\Identity\Presentation\Http\Controllers\AccountController;
use App\Modules\Identity\Presentation\Http\Controllers\AuthController;
use App\Modules\Location\Presentation\Http\Controllers\AddressSuggestController;
use App\Modules\Venue\Presentation\Http\Controllers\VenueController;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Support\Facades\Route;

$themeResolver = app(ThemeResolver::class);

Route::get('/', function () use ($themeResolver) {
    return $themeResolver->page('welcome');
})->name('welcome');

Route::get('/login', function () use ($themeResolver) {
    return $themeResolver->page('auth.login');
})->name('login');

Route::get('/register', function () use ($themeResolver) {
    return $themeResolver->page('auth.register');
})->name('register');

Route::prefix('faq')->group(function () use ($themeResolver) {
    Route::get('/', fn () => $themeResolver->page('faq.index'))
        ->name('faq.index')
        ->defaults('breadcrumb', 'FAQ');
    Route::get('/welcome', fn () => $themeResolver->page('faq.welcome'))
        ->name('faq.welcome')
        ->defaults('breadcrumb', 'Первые шаги');
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('guest', 'throttle:5,1')
        ->name('auth.login');

    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('guest', 'throttle:5,1')
        ->name('auth.register');

    Route::post('/restore', [AuthController::class, 'restore'])
        ->middleware('throttle:5,1')
        ->name('auth.restore');

    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth')
        ->name('auth.logout');

    Route::get('/logout', [AuthController::class, 'logout'])
        ->middleware('auth')
        ->name('logout');

    Route::post('/confirm', [AuthController::class, 'confirm'])
        ->middleware('auth', 'throttle:5,1')
        ->name('auth.confirm');

    /* trashed routes starts here DONT TOUCH SO FAR */
    Route::post('/resolve-login', [AuthController::class, 'resolveLogin'])
        ->middleware('throttle:10,1')
        ->name('auth.resolve-login');

    Route::post('/verify', [AuthController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('auth.verify');
    /* trashed routes ends here */

});

Route::prefix('admin')
    ->middleware('auth', 'can:access-admin-panel')
    ->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('admin.dashboard')->defaults('breadcrumb', 'Панель управления');
        Route::get('/users', [AdminController::class, 'users'])->name('admin.users')->defaults('breadcrumb', 'Пользователи');
        Route::get('/venues', [AdminVenuesController::class, 'index'])->name('admin.venues')->defaults('breadcrumb', 'Площадки');
        Route::get('/events', [AdminEventsController::class, 'index'])->name('admin.events')->defaults('breadcrumb', 'События');
        Route::get('/teams', [AdminTeamsController::class, 'index'])->name('admin.teams')->defaults('breadcrumb', 'Команды');
        Route::get('/content', [AdminContentController::class, 'index'])->name('admin.content')->defaults('breadcrumb', 'Контент');
        Route::get('/audit', [AdminAuditController::class, 'index'])->name('admin.audit')->defaults('breadcrumb', 'Аудит');
        Route::get('/settings', [AdminSettingsController::class, 'index'])->name('admin.settings');
    });

Route::prefix('venues')->group(function () {
    Route::get('/', [VenueController::class, 'index'])
        ->name('venues')
        ->defaults('breadcrumb', 'Площадки');
    Route::get('/create', [VenueController::class, 'create'])
        ->name('venues.create')
        ->defaults('breadcrumb', 'Добавить площадку');
    Route::post('/', [VenueController::class, 'store'])
        ->name('venues.store');
    Route::get('/{alias}', [VenueController::class, 'show'])->name('venues.show');
    Route::get('/{alias}/edit', [VenueController::class, 'edit'])->name('venues.edit');
    Route::get('/{alias}/remove', [VenueController::class, 'remove'])->name('venues.remove');
});

Route::get('/integrations/address-suggest', AddressSuggestController::class)
    ->middleware(['throttle:30,1'])
    ->name('integrations.address-suggest');

// Group routes for authenticated users
Route::middleware('auth')->group(function () use ($themeResolver) {

    // Dashboard route
    Route::get('/dashboard', fn () => $themeResolver->page('dashboard'))->name('dashboard');

    // Account routes
    Route::prefix('account')->group(function () {
        Route::get('/', [AccountController::class, 'index'])
            ->name('account')
            ->defaults('breadcrumb', 'Аккаунт');
        Route::get('/confirmation', [AccountController::class, 'confirmation'])
            ->name('account.confirmation')
            ->defaults('breadcrumb', 'Подтверждение аккаунта');
        Route::post('/confirmation', [AccountController::class, 'completeConfirmation'])
            ->name('account.confirmation.complete');
        Route::post('/confirmation/contact', [AccountController::class, 'storeConfirmationContact'])
            ->name('account.confirmation.contact.store');
        Route::post('/confirmation/contacts/{contact}/verification', [AccountController::class, 'startConfirmationContactVerification'])
            ->middleware('throttle:10,1')
            ->name('account.confirmation.contacts.verification.store');
        Route::post('/confirmation/contacts/{contact}/verification/confirm', [AccountController::class, 'confirmConfirmationContactVerification'])
            ->middleware('throttle:20,1')
            ->name('account.confirmation.contacts.verification.confirm');
        Route::get('/participation/{role}', [AccountController::class, 'participationRole'])
            ->name('account.participation-role');
        Route::get('/settings', [AccountController::class, 'settings'])->name('account.settings');
        Route::get('/notifications', [AccountController::class, 'notifications'])->name('account.notifications');
        Route::patch('/notifications/read-all', [AccountController::class, 'readAllNotifications'])
            ->name('account.notifications.read-all');
        Route::patch('/notifications/{notification}/read', [AccountController::class, 'readNotification'])
            ->name('account.notifications.read');
        Route::get('/contacts', [AccountController::class, 'contacts'])->name('account.contacts');
        Route::post('/contacts', [AccountController::class, 'storeContact'])->name('account.contacts.store');
        Route::patch('/contacts/{contact}/primary', [AccountController::class, 'setPrimaryContact'])
            ->name('account.contacts.primary');
        Route::delete('/contacts/{contact}', [AccountController::class, 'destroyContact'])
            ->name('account.contacts.destroy');
        Route::post('/contacts/{contact}/verification', [AccountController::class, 'startContactVerification'])
            ->middleware('throttle:10,1')
            ->name('account.contacts.verification.store');
        Route::post('/contacts/{contact}/verification/confirm', [AccountController::class, 'confirmContactVerification'])
            ->middleware('throttle:20,1')
            ->name('account.contacts.verification.confirm');
        Route::get('/contracts', [AccountController::class, 'contracts'])->name('account.contracts');
        Route::get('/contracts/{number}', [AccountController::class, 'contract'])->name('account.contracts.show');
        Route::get('/venues', [AccountController::class, 'venues'])->name('account.venues');
        Route::get('/venues/{alias}', [AccountController::class, 'showVenue'])->name('account.venues.show');
        Route::get('/venues/{alias}/schedule', [AccountController::class, 'editVenueSchedule'])
            ->name('account.venues.schedule.edit');
        Route::put('/venues/{alias}/schedule', [AccountController::class, 'updateVenueSchedule'])
            ->name('account.venues.schedule.update');
        Route::get('/venues/{alias}/edit', [AccountController::class, 'editVenue'])->name('account.venues.edit');
    });
});
