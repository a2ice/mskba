<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;

final readonly class VenueBookingAuthorization
{
    public function __construct(private VenueCommercialAccess $commercialAccess) {}

    public function assertCommercialDecision(Actor $actor, Venue $venue): void
    {
        $user = $actor->user;
        if ($user === null || ! $this->commercialAccess->allows($user, $venue, VenuePermissionEnum::DECIDE_BOOKING_REQUESTS)) {
            throw new VenueBookingTransitionException('Недостаточно прав для решения по заявке.', 'BOOKING_FORBIDDEN');
        }
    }

    public function assertCanView(Actor $actor, VenueBooking $booking, Venue $venue): void
    {
        if ($actor->user_id === $booking->requester_user_id) {
            return;
        }

        $user = $actor->user;
        if ($user === null || ! $this->commercialAccess->allows($user, $venue, VenuePermissionEnum::VIEW_BOOKING_REQUESTS)) {
            throw new VenueBookingTransitionException('Недостаточно прав для просмотра заявки.', 'BOOKING_FORBIDDEN');
        }
    }

    public function assertCanCancel(Actor $actor, VenueBooking $booking, Venue $venue): void
    {
        if ($actor->user_id === $booking->requester_user_id) {
            return;
        }

        $this->assertCommercialDecision($actor, $venue);
    }
}
