<?php

namespace App\Modules\VenueBooking\Application\Queries;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Database\Eloquent\Builder;

final readonly class CountActionableVenueBookingRequests
{
    public function __construct(private VenueMembershipAccess $memberships) {}

    public function totalFor(User $user): int
    {
        return $this->queryFor($user)->count();
    }

    /** @return array<int, int> */
    public function byVenueFor(User $user): array
    {
        return $this->queryFor($user)
            ->selectRaw('venue_id, COUNT(*) AS aggregate')
            ->groupBy('venue_id')
            ->pluck('aggregate', 'venue_id')
            ->mapWithKeys(fn (mixed $count, mixed $venueId): array => [(int) $venueId => (int) $count])
            ->all();
    }

    private function queryFor(User $user): Builder
    {
        $user = $user->canonical();
        $query = VenueBooking::query()
            ->where('flow', 'rental')
            ->where('status', VenueBookingStatusEnum::REQUESTED->value);

        if ($user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            return $query;
        }

        return $query->whereIn(
            'venue_id',
            $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::DECIDE_BOOKING_REQUESTS),
        );
    }
}
