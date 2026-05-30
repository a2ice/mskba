<?php

use App\Providers\AppServiceProvider;
use App\Modules\Venue\Infrastructure\Providers\VenueAccessServiceProvider;

return [
    AppServiceProvider::class,
    VenueAccessServiceProvider::class,
];
