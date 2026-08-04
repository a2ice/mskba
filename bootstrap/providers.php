<?php

use App\Modules\Contact\Infrastructure\Providers\ContactServiceProvider;
use App\Modules\Event\Infrastructure\Providers\EventLifecycleServiceProvider;
use App\Modules\Media\Infrastructure\Providers\MediaServiceProvider;
use App\Modules\Notification\Infrastructure\Providers\NotificationServiceProvider;
use App\Modules\Team\Infrastructure\Providers\TeamSportsServiceProvider;
use App\Modules\Telegram\Infrastructure\Providers\TelegramServiceProvider;
use App\Modules\Venue\Infrastructure\Providers\VenueAccessServiceProvider;
use App\Providers\AccessServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AccessServiceProvider::class,
    ContactServiceProvider::class,
    EventLifecycleServiceProvider::class,
    MediaServiceProvider::class,
    NotificationServiceProvider::class,
    TeamSportsServiceProvider::class,
    TelegramServiceProvider::class,
    VenueAccessServiceProvider::class,
];
