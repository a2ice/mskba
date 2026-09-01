<?php

namespace App\Modules\VenueBooking\Application\Queries;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\VenueBooking\Application\Services\VenueBookingActionState;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;

final readonly class ListOwnerBookingInbox
{
    public function __construct(private VenueMembershipAccess $memberships, private VenueBookingActionState $actions, private GetBookingDetails $details) {}

    /** @param list<string> $statuses
     * @return array<string, mixed>
     */
    public function handle(Actor $actor, array $statuses = [], ?int $venueId = null, int $page = 1, int $perPage = 20): array
    {
        abort_if($actor->user === null, 403);
        $user = $actor->user->canonical();
        $superadmin = $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN);
        $venueIds = $superadmin ? null : $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::VIEW_BOOKING_REQUESTS);
        $decideVenueIds = $superadmin ? null : $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::DECIDE_BOOKING_REQUESTS);
        $paymentVenueIds = $superadmin ? null : $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::VIEW_PAYMENTS);
        if (! $superadmin && $venueIds === []) {
            abort(403);
        }

        $paginator = VenueBooking::query()->with(['venue', 'event', 'paymentAttempt', 'extensionRequests'])->where('flow', 'rental')
            ->when($venueIds !== null, fn ($query) => $query->whereIn('venue_id', $venueIds))
            ->when($venueId !== null, fn ($query) => $query->where('venue_id', $venueId))
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->latest('updated_at')->paginate(min(50, max(1, $perPage)), ['*'], 'page', max(1, $page));

        $data = collect($paginator->items())->map(function (VenueBooking $booking) use ($actor, $decideVenueIds, $paymentVenueIds): array {
            $canDecide = $decideVenueIds === null || in_array($booking->venue_id, $decideVenueIds, true);
            $canViewPayment = $paymentVenueIds === null || in_array($booking->venue_id, $paymentVenueIds, true);
            $actions = $this->actions->for($booking, $actor, $canDecide);

            return $this->details->serialize($booking, $actions, $this->actions->primary($actions, false), $canViewPayment);
        })->all();

        return ['data' => $data, 'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }
}
