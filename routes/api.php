<?php

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

Route::middleware(['auth:sanctum', EnsureActiveMobileApiUser::class])->group(function (): void {
    Route::get('/v1/me', [MobileAuthController::class, 'me'])
        ->middleware('throttle:60,1')
        ->name('api.v1.me');

    Route::post('/v1/auth/logout', [MobileAuthController::class, 'logout'])
        ->middleware('throttle:20,1')
        ->name('api.v1.auth.logout');
});
