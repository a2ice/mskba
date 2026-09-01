<?php

namespace App\Support\Features;

use RuntimeException;

final class FeatureDisabledException extends RuntimeException
{
    public function __construct(public readonly VenueRentalFeature $feature)
    {
        parent::__construct('feature_disabled');
    }
}
