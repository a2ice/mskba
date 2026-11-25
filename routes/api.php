<?php

use App\Modules\Content\Presentation\Http\Controllers\Api\V1\EditorialNewsAgentApiController;
use App\Modules\Content\Presentation\Http\Controllers\Api\V1\PageBuilderAgentApiController;
use App\Modules\Content\Presentation\Http\Controllers\Api\V1\ProjectUpdateAgentApiController;
use App\Modules\Ai\Presentation\Http\Controllers\GitHubOpenAiGenerationCallbackController;
use App\Modules\Ai\Presentation\Http\Controllers\GitHubOpenAiGenerationManifestController;
use App\Modules\Ai\Presentation\Http\Controllers\GitHubOpenAiGenerationReferenceController;
use App\Modules\Telegram\Presentation\Http\Controllers\TelegramWebhookController;
use App\Modules\VenueBooking\Presentation\Http\Controllers\PaymentWebhookController;
use App\Modules\Venue\Presentation\Http\Controllers\Api\V1\ListPublicVenuesController;
use App\Modules\Venue\Presentation\Http\Controllers\Api\V1\ShowPublicVenueController;
use App\Modules\Venue\Presentation\Http\Controllers\Api\V1\HomepageVenueMapController;
use App\Modules\Tournament\Presentation\Http\Controllers\Api\V1\HomepageTournamentsSceneController;
use App\Modules\Identity\Infrastructure\Http\Middleware\EnsureActiveMobileApiUser;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\MobileAuthController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\MobileAccountConfirmationController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\MobileDeleteAccountController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\RestoreAccountController;
use Illuminate\Support\Facades\Route;

Route::post('/integrations/telegram/webhook', TelegramWebhookController::class)
    ->middleware('throttle:300,1')
    ->name('integrations.telegram.webhook');

Route::post('/integrations/venue-rental-payments/{provider}/webhook', PaymentWebhookController::class)
    ->middleware(['venue-rental-feature:payment_port', 'throttle:300,1'])
    ->where('provider', '[a-z0-9_-]+')
    ->name('integrations.venue-rental-payments.webhook');

Route::get('/integrations/github-openai/player-character/{generation}/manifest', GitHubOpenAiGenerationManifestController::class)
    ->middleware(['signed', 'throttle:30,1'])
    ->name('integrations.github-openai.player-character.manifest');

Route::get('/integrations/github-openai/player-character/{generation}/references/{slot}', GitHubOpenAiGenerationReferenceController::class)
    ->middleware(['signed', 'throttle:60,1'])
    ->where('slot', 'front|left|right|team_logo')
    ->name('integrations.github-openai.player-character.reference');

Route::post('/integrations/github-openai/player-character/{generation}/callback', GitHubOpenAiGenerationCallbackController::class)
    ->middleware('throttle:30,1')
    ->name('integrations.github-openai.player-character.callback');

// Public read-only mobile API: no embedded application secret or user session.
Route::get('/v1/venues', ListPublicVenuesController::class)
    ->middleware('throttle:60,1')
    ->name('api.v1.venues.index');

// Single published venue, same public fields and visibility rules as the list.
Route::get('/v1/venues/{venue}', ShowPublicVenueController::class)
    ->whereNumber('venue')
    ->middleware('throttle:60,1')
    ->name('api.v1.venues.show');

// Dedicated read-only public homepage map feed, with real district/surface/amenity filters.
Route::get('/v1/home/venues-map', HomepageVenueMapController::class)
    ->middleware('throttle:60,1')
    ->name('api.v1.home.venues-map');

// Public read-only homepage tournaments listing and named scene-art lookup.
Route::get('/v1/home/tournaments-scene', HomepageTournamentsSceneController::class)
    ->middleware('throttle:60,1')
    ->name('api.v1.home.tournaments-scene');

/*
 * Platform-neutral credential/token API for trusted first-party mobile clients.
 * No web session or CSRF cookie is required for these routes.
 */
Route::post('/v1/auth/login', [MobileAuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('api.v1.auth.login');

Route::post('/v1/auth/register', [MobileAuthController::class, 'register'])
    ->middleware('throttle:5,1')
    ->name('api.v1.auth.register');

// Restoration is public because all Bearer tokens were revoked on deletion.
// A one-time code sent to a previously verified email is mandatory.
Route::post('/v1/account/restore/request', [RestoreAccountController::class, 'requestCode'])
    ->middleware('throttle:5,1')
    ->name('api.v1.account.restore.request');
Route::post('/v1/account/restore/confirm', [RestoreAccountController::class, 'confirm'])
    ->middleware('throttle:10,1')
    ->name('api.v1.account.restore.confirm');

Route::middleware(['auth:sanctum', EnsureActiveMobileApiUser::class])->group(function (): void {
    Route::get('/v1/me', [MobileAuthController::class, 'me'])
        ->middleware('throttle:60,1')
        ->name('api.v1.me');

    Route::get('/v1/account/confirmation', [MobileAccountConfirmationController::class, 'status'])
        ->middleware('throttle:60,1')
        ->name('api.v1.account.confirmation.show');

    Route::patch('/v1/me/profile', [MobileAccountConfirmationController::class, 'updateProfile'])
        ->middleware('throttle:20,1')
        ->name('api.v1.me.profile.update');

    Route::get('/v1/me/contacts', [MobileAccountConfirmationController::class, 'contacts'])
        ->middleware('throttle:60,1')
        ->name('api.v1.me.contacts.index');

    Route::post('/v1/me/contacts', [MobileAccountConfirmationController::class, 'createContact'])
        ->middleware('throttle:10,1')
        ->name('api.v1.me.contacts.store');

    Route::post('/v1/me/contacts/{contact}/verification', [MobileAccountConfirmationController::class, 'sendCode'])
        ->whereNumber('contact')
        ->middleware('throttle:5,1')
        ->name('api.v1.me.contacts.verification.store');

    Route::post('/v1/me/contacts/{contact}/verification/confirm', [MobileAccountConfirmationController::class, 'confirmCode'])
        ->whereNumber('contact')
        ->middleware('throttle:10,1')
        ->name('api.v1.me.contacts.verification.confirm');

    Route::patch('/v1/me/contacts/{contact}/primary', [MobileAccountConfirmationController::class, 'setPrimary'])
        ->whereNumber('contact')
        ->middleware('throttle:10,1')
        ->name('api.v1.me.contacts.primary');

    Route::post('/v1/account/confirmation', [MobileAccountConfirmationController::class, 'confirm'])
        ->middleware('throttle:5,1')
        ->name('api.v1.account.confirmation.complete');

    Route::delete('/v1/me', MobileDeleteAccountController::class)
        ->middleware('throttle:5,1')
        ->name('api.v1.me.destroy');

    Route::post('/v1/auth/logout', [MobileAuthController::class, 'logout'])
        ->middleware('throttle:20,1')
        ->name('api.v1.auth.logout');
});


/*
 * Staging-only Page Builder agent bridge.
 * Every request requires a dedicated, short-lived, capability-scoped
 * Sanctum Personal Access Token AND the superadmin's Filament permissions.
 * The controller also fails closed on anything other than dev.mskba.ru.
 */
Route::middleware(['auth:sanctum', 'throttle:30,1'])
    ->prefix('v1/internal/page-builder')
    ->group(function (): void {
        Route::get('/{kind}/{key}', [PageBuilderAgentApiController::class, 'show'])
            ->name('api.internal.page-builder.show');
        Route::patch('/{kind}/{key}/draft', [PageBuilderAgentApiController::class, 'stage'])
            ->middleware('throttle:10,1')
            ->name('api.internal.page-builder.draft');
        Route::post('/{kind}/{key}/publish', [PageBuilderAgentApiController::class, 'publish'])
            ->middleware('throttle:6,1')
            ->name('api.internal.page-builder.publish');
        Route::post('/{kind}/{key}/rollback', [PageBuilderAgentApiController::class, 'rollback'])
            ->middleware('throttle:6,1')
            ->name('api.internal.page-builder.rollback');
    });


/*
 * Only dedicated short-lived scoped tokens belonging to the system user dev
 * may create/update unpublished project updates. Public publishing is never
 * available through this integration. Works on dev and production.
 */
Route::middleware(['auth:sanctum', 'throttle:15,1'])
    ->prefix('v1/internal/project-updates')
    ->group(function (): void {
        Route::post('/', [ProjectUpdateAgentApiController::class, 'store'])
            ->name('api.internal.project-updates.store');
        Route::get('/{contentItem}', [ProjectUpdateAgentApiController::class, 'show'])
            ->whereNumber('contentItem')
            ->name('api.internal.project-updates.show');
    });


// Editorial materials only; separate explicit write and publication abilities.
Route::middleware(['auth:sanctum', 'throttle:15,1'])
    ->prefix('v1/internal/editorial-news')
    ->group(function (): void {
        Route::post('/', [EditorialNewsAgentApiController::class, 'store'])
            ->name('api.internal.editorial-news.store');
        Route::get('/destinations/list', [EditorialNewsAgentApiController::class, 'destinations'])
            ->name('api.internal.editorial-news.destinations');
        Route::get('/{alias}', [EditorialNewsAgentApiController::class, 'show'])
            ->where('alias', '[a-z0-9-]+')
            ->name('api.internal.editorial-news.show');
        Route::post('/{alias}/publish', [EditorialNewsAgentApiController::class, 'publish'])
            ->where('alias', '[a-z0-9-]+')
            ->name('api.internal.editorial-news.publish');
        Route::post('/{alias}/telegram/{chatId}/retry', [EditorialNewsAgentApiController::class, 'retryTelegram'])
            ->where(['alias' => '[a-z0-9-]+', 'chatId' => '[0-9]+'])
            ->name('api.internal.editorial-news.telegram.retry');
    });
