<?php

namespace App\Support\Features;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;

final class VenueRentalRollout
{
    public function allows(VenueRentalFeature $feature, ?User $user, ?int $venueId, ?int $contractId, string $stableKey, bool $write): bool
    {
        $mode = (string) config('features.venue_rental_rollout.mode', 'all');
        if ($mode === 'off' || ($mode === 'read_only' && $write)) {
            return false;
        }
        if (in_array($mode, ['all', 'read_only'], true)) {
            return true;
        }

        $user = $user?->canonical();
        if ($mode === 'internal') {
            return $user?->hasSystemRole(UserSystemRoleEnum::SUPERADMIN) === true;
        }

        $allowlisted = ($user !== null && in_array($user->id, config('features.venue_rental_rollout.user_ids', []), true))
            || ($venueId !== null && in_array($venueId, config('features.venue_rental_rollout.venue_ids', []), true))
            || ($contractId !== null && in_array($contractId, config('features.venue_rental_rollout.contract_ids', []), true));
        if ($mode === 'allowlist') {
            return $allowlisted;
        }
        if ($allowlisted) {
            return true;
        }

        $percentage = min(100, max(0, (int) config('features.venue_rental_rollout.percentage', 0)));

        $bucket = (int) sprintf('%u', crc32($feature->value.':'.$stableKey)) % 100;

        return $mode === 'percentage' && $bucket < $percentage;
    }
}
