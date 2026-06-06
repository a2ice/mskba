<?php

namespace App\Modules\Notification\Infrastructure\Providers;

use App\Modules\Contact\Domain\Events\UserContactConfirmed;
use App\Modules\Identity\Domain\Events\UserFirstLogin;
use App\Modules\Notification\Infrastructure\Listeners\CreateContactConfirmedNotification;
use App\Modules\Notification\Infrastructure\Listeners\CreateWelcomeNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(UserFirstLogin::class, CreateWelcomeNotification::class);
        Event::listen(UserContactConfirmed::class, CreateContactConfirmedNotification::class);
    }
}
