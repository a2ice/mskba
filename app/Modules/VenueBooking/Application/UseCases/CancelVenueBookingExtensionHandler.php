<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingExtensionStatus;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingExtensionRequest;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class CancelVenueBookingExtensionHandler
{
    public function __construct(private LockedVenueBooking $lockedBooking, private FeatureFlags $features) {}

    public function handle(int $bookingId, int $extensionRequestId, Actor $actor): VenueBookingExtensionRequest
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking) use ($extensionRequestId, $actor): VenueBookingExtensionRequest {
            if ($actor->user_id === null || $actor->user_id !== $booking->requester_user_id) {
                throw new VenueBookingTransitionException('Только заявитель может отменить запрос продления.', 'BOOKING_FORBIDDEN');
            }
            $extension = VenueBookingExtensionRequest::query()->lockForUpdate()->findOrFail($extensionRequestId);
            if ($extension->venue_booking_id !== $booking->id) {
                throw new VenueBookingTransitionException('Запрос продления не относится к этой брони.', 'EXTENSION_BOOKING_MISMATCH');
            }
            if ($extension->status === VenueBookingExtensionStatus::CANCELLED) {
                return $extension;
            }
            if ($extension->status !== VenueBookingExtensionStatus::PENDING) {
                throw new VenueBookingTransitionException('Запрос на продление уже закрыт.', 'EXTENSION_ALREADY_DECIDED');
            }
            $extension->update([
                'status' => VenueBookingExtensionStatus::CANCELLED,
                'active_marker' => null,
                'cancelled_at' => now(),
            ]);

            return $extension->fresh();
        });
    }
}
