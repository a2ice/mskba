<?php

namespace App\Modules\Coordination\Infrastructure\Listeners;

use App\Modules\Coordination\Application\UseCases\CloseVenueBookingAttendanceRoundHandler;
use App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceRoundStatus;
use App\Modules\Coordination\Domain\Models\VenueBookingAttendanceRound;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\VenueBooking\Domain\Events\VenueBookingCancelled;
use App\Modules\VenueBooking\Domain\Events\VenueBookingConfirmed;
use App\Modules\VenueBooking\Domain\Events\VenueBookingExpired;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRejected;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class CloseAttendanceRoundAfterBookingEnds
{
    public function __construct(
        private FeatureFlags $features,
        private CurrentActorResolver $actors,
        private CloseVenueBookingAttendanceRoundHandler $close,
    ) {}

    public function handle(VenueBookingConfirmed|VenueBookingCancelled|VenueBookingExpired|VenueBookingRejected $event): void
    {
        if (! $this->features->enabled(VenueRentalFeature::ATTENDANCE_V2)) {
            return;
        }
        $roundId = VenueBookingAttendanceRound::query()
            ->where('venue_booking_id', $event->bookingId)
            ->where('status', VenueBookingAttendanceRoundStatus::OPEN)
            ->value('id');
        if ($roundId !== null) {
            $this->close->handle((int) $roundId, $this->actors->system(), 'booking_status_changed');
        }
    }
}
