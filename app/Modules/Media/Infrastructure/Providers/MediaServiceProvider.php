<?php

namespace App\Modules\Media\Infrastructure\Providers;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueRevision;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'profile' => Profile::class,
            'event' => Event::class,
            'venue' => Venue::class,
            'venue_revision' => VenueRevision::class,
        ]);
    }
}
