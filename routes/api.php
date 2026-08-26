<?php

use App\Modules\Telegram\Presentation\Http\Controllers\TelegramWebhookController;
use App\Modules\VenueBooking\Presentation\Http\Controllers\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/integrations/telegram/webhook', TelegramWebhookController::class)
    ->middleware('throttle:300,1')
    ->name('integrations.telegram.webhook');

Route::post('/integrations/venue-rental-payments/{provider}/webhook', PaymentWebhookController::class)
    ->middleware(['venue-rental-feature:payment_port', 'throttle:300,1'])
    ->where('provider', '[a-z0-9_-]+')
    ->name('integrations.venue-rental-payments.webhook');
