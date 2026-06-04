<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AccessServiceProvider::class,
    App\Modules\Contact\Infrastructure\Providers\ContactServiceProvider::class,
    App\Modules\Notification\Infrastructure\Providers\NotificationServiceProvider::class,
    App\Modules\Venue\Infrastructure\Providers\VenueAccessServiceProvider::class,
];
