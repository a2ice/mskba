<?php

use App\Modules\Acquisition\Infrastructure\Providers\AcquisitionServiceProvider;
use App\Modules\Contact\Infrastructure\Providers\ContactServiceProvider;
use App\Modules\Coordination\Infrastructure\Providers\CoordinationInterfaceServiceProvider;
use App\Modules\Event\Infrastructure\Providers\EventLifecycleServiceProvider;
use App\Modules\Identity\Infrastructure\Providers\IdentityCanonicalizationServiceProvider;
use App\Modules\Media\Infrastructure\Providers\MediaServiceProvider;
use App\Modules\Notification\Infrastructure\Providers\NotificationServiceProvider;
use App\Modules\SportsSection\Infrastructure\Providers\SportsSectionServiceProvider;
use App\Modules\Team\Infrastructure\Providers\TeamSportsServiceProvider;
use App\Modules\Telegram\Infrastructure\Providers\TelegramServiceProvider;
use App\Modules\Template\Infrastructure\Providers\TemplateServiceProvider;
use App\Modules\Venue\Infrastructure\Providers\VenueAccessServiceProvider;
use App\Modules\Venue\Infrastructure\Providers\VenueCourtServiceProvider;
use App\Modules\Venue\Infrastructure\Providers\VenueOwnershipServiceProvider;
use App\Modules\VenueBooking\Infrastructure\Providers\VenueBookingServiceProvider;
use App\Modules\Vk\Infrastructure\Providers\VkServiceProvider;
use App\Providers\AccessServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AcquisitionServiceProvider::class,
    AccessServiceProvider::class,
    ContactServiceProvider::class,
    CoordinationInterfaceServiceProvider::class,
    EventLifecycleServiceProvider::class,
    IdentityCanonicalizationServiceProvider::class,
    MediaServiceProvider::class,
    NotificationServiceProvider::class,
    SportsSectionServiceProvider::class,
    TeamSportsServiceProvider::class,
    TelegramServiceProvider::class,
    TemplateServiceProvider::class,
    VenueAccessServiceProvider::class,
    VenueCourtServiceProvider::class,
    VenueOwnershipServiceProvider::class,
    VenueBookingServiceProvider::class,
    VkServiceProvider::class,
];
