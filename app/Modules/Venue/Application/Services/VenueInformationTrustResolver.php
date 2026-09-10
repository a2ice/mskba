<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Enums\VenueOwnershipStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnership;

final class VenueInformationTrustResolver
{
    public const MINIMUM_TRUSTED_SCORE = 70;

    public function __construct(
        private readonly VenueMembershipAccess $membershipAccess,
    ) {}

    public function isTrusted(Venue $venue): bool
    {
        $membership = $this->membershipAccess->activeOwnerMembership($venue);

        if ($membership === null) {
            return false;
        }

        $ownership = VenueOwnership::query()
            ->where('venue_id', $venue->id)
            ->where('contract_membership_id', $membership->id)
            ->where('status', VenueOwnershipStatusEnum::ACTIVE->value)
            ->first();

        return $ownership !== null
            && $ownership->maintenance_commitment_accepted
            && $ownership->maintenance_score >= self::MINIMUM_TRUSTED_SCORE;
    }
}
