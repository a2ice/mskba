<?php

namespace App\Modules\VenueBooking\Infrastructure\Listeners;

use App\Modules\Venue\Application\Services\VenueSearchCache;
use App\Modules\VenueBooking\Domain\Events\VenueBookingPolicyPublished;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class InvalidateVenueSearchAfterPolicyPublished
{
    public function __construct(
        private VenueSearchCache $cache,
        private FeatureFlags $features,
    ) {}

    public function handle(VenueBookingPolicyPublished $event): void
    {
        if ($this->features->enabled(VenueRentalFeature::RENTAL_FLOW)) {
            $this->cache->invalidate();
        }
    }
}
