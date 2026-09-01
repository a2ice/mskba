<?php

namespace App\Modules\VenueBooking\Application\Queries;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;

final readonly class GetBookingTimeline
{
    public function __construct(private VenueBookingAuthorization $authorization) {}

    /** @return array<string, mixed> */
    public function handle(VenueBooking $booking, Actor $actor, int $page = 1, int $perPage = 30): array
    {
        $booking->loadMissing('venue');
        $this->authorization->assertCanView($actor, $booking, $booking->venue);
        $paginator = $booking->transitions()->with('actor.user')->paginate(min(50, max(1, $perPage)), ['*'], 'page', max(1, $page));

        return [
            'booking_id' => $booking->public_id,
            'version' => $booking->optimistic_version,
            'data' => collect($paginator->items())->map(fn ($transition): array => [
                'from' => $transition->from_status?->value,
                'to' => $transition->to_status->value,
                'label' => $transition->to_status->label(),
                'reason' => $transition->reason,
                'actor' => $transition->actor?->user?->username,
                'version' => $transition->booking_version,
                'occurred_at' => $transition->created_at->utc()->toIso8601String(),
            ])->all(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ];
    }
}
