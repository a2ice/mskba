<?php

use App\Modules\Contact\Infrastructure\Providers\ContactServiceProvider;
use App\Modules\Coordination\Infrastructure\Providers\CoordinationInterfaceServiceProvider;
use App\Modules\Event\Infrastructure\Providers\EventLifecycleServiceProvider;
use App\Modules\Identity\Infrastructure\Providers\IdentityCanonicalizationServiceProvider;
use App\Modules\Media\Infrastructure\Providers\MediaServiceProvider;
use App\Modules\Notification\Infrastructure\Providers\NotificationServiceProvider;
use App\Modules\Team\Infrastructure\Providers\TeamSportsServiceProvider;
use App\Modules\Telegram\Infrastructure\Providers\TelegramServiceProvider;
use App\Modules\Venue\Infrastructure\Providers\VenueAccessServiceProvider;
use App\Modules\VenueBooking\Infrastructure\Providers\VenueBookingServiceProvider;
use App\Modules\Vk\Infrastructure\Providers\VkServiceProvider;
use App\Providers\AccessServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AccessServiceProvider::class,
    ContactServiceProvider::class,
    CoordinationInterfaceServiceProvider::class,
    EventLifecycleServiceProvider::class,
    IdentityCanonicalizationServiceProvider::class,
    MediaServiceProvider::class,
    NotificationServiceProvider::class,
    TeamSportsServiceProvider::class,
    TelegramServiceProvider::class,
    VenueAccessServiceProvider::class,
    VenueBookingServiceProvider::class,
    VkServiceProvider::class,
];
