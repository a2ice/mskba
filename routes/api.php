<?php

use App\Modules\Ai\Presentation\Http\Controllers\GitHubOpenAiGenerationCallbackController;
use App\Modules\Ai\Presentation\Http\Controllers\GitHubOpenAiGenerationManifestController;
use App\Modules\Ai\Presentation\Http\Controllers\GitHubOpenAiGenerationReferenceController;
use App\Modules\Telegram\Presentation\Http\Controllers\TelegramWebhookController;
use App\Modules\VenueBooking\Presentation\Http\Controllers\PaymentWebhookController;
use App\Modules\Venue\Presentation\Http\Controllers\Api\V1\ListPublicVenuesController;
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

// Compatibility with the first iOS integration path; same contract and rules.
Route::get('/get_venues', ListPublicVenuesController::class)
    ->middleware('throttle:60,1')
    ->name('api.legacy.get_venues');
