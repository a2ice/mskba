<?php

namespace App\Modules\VenueBooking\Application\Queries;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\VenueBookingActionState;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;

final readonly class ListRequesterBookings
{
    public function __construct(private VenueBookingActionState $actions, private GetBookingDetails $details) {}

    /** @return array<string, mixed> */
    public function handle(Actor $actor, int $page = 1, int $perPage = 20): array
    {
        abort_if($actor->user === null, 403);
        $paginator = VenueBooking::query()->with(['venue', 'event', 'paymentAttempt', 'extensionRequests'])->where('flow', 'rental')
            ->whereIn('requester_user_id', $actor->user->identityIds())
            ->latest('updated_at')->paginate(min(50, max(1, $perPage)), ['*'], 'page', max(1, $page));
        $data = collect($paginator->items())->map(function (VenueBooking $booking) use ($actor): array {
            $actions = $this->actions->for($booking, $actor, false);

            return $this->details->serialize($booking, $actions, $this->actions->primary($actions, true), true);
        })->all();

        return ['data' => $data, 'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }
}
