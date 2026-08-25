<?php

namespace App\Support\Features;

use Illuminate\Contracts\Config\Repository;

final readonly class FeatureFlags
{
    public function __construct(private Repository $config) {}

    public function enabled(VenueRentalFeature $feature): bool
    {
        return $this->config->boolean("features.venue_rental.{$feature->value}");
    }

    public function ensureEnabled(VenueRentalFeature $feature): void
    {
        if (! $this->enabled($feature)) {
            throw new FeatureDisabledException($feature);
        }
    }
}
