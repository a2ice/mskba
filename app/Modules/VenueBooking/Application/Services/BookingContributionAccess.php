<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Coordination\Domain\Models\VenueRentalCoordinationParticipant;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;

final class BookingContributionAccess
{
    public function assertCanContribute(Actor $actor, VenueBooking $booking): void
    {
        if (! $this->isParticipant($actor, $booking)) {
            throw new VenueBookingTransitionException('Только участник сбора может изменить своё обещание.', 'CONTRIBUTION_FORBIDDEN');
        }
    }

    public function assertCanViewSummary(Actor $actor, VenueBooking $booking): void
    {
        if (! $this->isParticipant($actor, $booking) && ! $this->isSuperadmin($actor)) {
            throw new VenueBookingTransitionException('Недостаточно прав для просмотра сбора.', 'CONTRIBUTION_FORBIDDEN');
        }
    }

    public function isOrganizer(Actor $actor, VenueBooking $booking): bool
    {
        return $actor->user !== null
            && in_array($booking->requester_user_id, $actor->user->identityIds(), true);
    }

    public function isSuperadmin(Actor $actor): bool
    {
        return $actor->user?->canonical()->hasSystemRole(UserSystemRoleEnum::SUPERADMIN) === true;
    }

    public function isParticipant(Actor $actor, VenueBooking $booking): bool
    {
        if ($actor->user === null) {
            return false;
        }

        $userIds = $actor->user->identityIds();
        if (in_array($booking->requester_user_id, $userIds, true)) {
            return true;
        }

        if (VenueRentalCoordinationParticipant::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('left_at')
            ->whereHas('coordination', fn ($query) => $query->where('venue_booking_id', $booking->id))
            ->exists()) {
            return true;
        }

        return $booking->event_id !== null
            && EventParticipant::query()
                ->where('event_id', $booking->event_id)
                ->whereIn('user_id', $userIds)
                ->where('status', '!=', EventParticipantStatusEnum::LEFT->value)
                ->exists();
    }
}
