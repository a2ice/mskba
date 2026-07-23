<?php

use App\Modules\Telegram\Presentation\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/integrations/telegram/webhook', TelegramWebhookController::class)
    ->middleware('throttle:300,1')
    ->name('integrations.telegram.webhook');
