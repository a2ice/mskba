<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingExtensionStatus;
use App\Modules\VenueBooking\Domain\Events\VenueBookingExtensionApproved;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingExtensionRequest;
use App\Modules\VenueBooking\Infrastructure\Jobs\ExpireVenueBookingIfDueJob;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ApproveVenueBookingExtensionHandler
{
    public function __construct(
        private LockedVenueBooking $lockedBooking,
        private VenueBookingAuthorization $authorization,
        private FeatureFlags $features,
    ) {}

    public function handle(int $bookingId, int $extensionRequestId, Actor $actor, ?string $reason = null): VenueBookingExtensionRequest
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking, $venue) use ($extensionRequestId, $actor, $reason): VenueBookingExtensionRequest {
            $this->authorization->assertCommercialDecision($actor, $venue);
            $extension = VenueBookingExtensionRequest::query()->lockForUpdate()->findOrFail($extensionRequestId);
            $this->assertBelongsToBooking($extension, $booking);

            if ($extension->status === VenueBookingExtensionStatus::APPROVED) {
                return $extension;
            }
            if ($extension->status !== VenueBookingExtensionStatus::PENDING) {
                throw new VenueBookingTransitionException('Запрос на продление уже закрыт.', 'EXTENSION_ALREADY_DECIDED');
            }

            $now = CarbonImmutable::now();
            if ($booking->status !== VenueBookingStatusEnum::HELD
                || $booking->effective_protection_until === null
                || ! $now->lessThan($booking->effective_protection_until)) {
                throw new VenueBookingTransitionException('Срок удержания уже истёк.', 'HOLD_EXPIRED');
            }

            RequestVenueBookingExtensionHandler::assertWithinPolicy($booking, $extension->requested_until);
            $nextVersion = $booking->optimistic_version + 1;
            $booking->forceFill([
                'effective_protection_until' => $extension->requested_until,
                'optimistic_version' => $nextVersion,
            ])->save();
            $extension->update([
                'status' => VenueBookingExtensionStatus::APPROVED,
                'active_marker' => null,
                'reviewed_by_actor_id' => $actor->id,
                'decision_reason' => $reason,
                'decided_at' => $now,
            ]);

            $bookingId = $booking->id;
            $extensionId = $extension->id;
            $deadline = $extension->requested_until;
            DB::afterCommit(static function () use ($bookingId, $extensionId, $nextVersion, $deadline): void {
                event(new VenueBookingExtensionApproved($bookingId, $extensionId));
                ExpireVenueBookingIfDueJob::dispatch($bookingId, $nextVersion, $deadline->toIso8601String())->delay($deadline);
            });

            return $extension->fresh();
        }, lockConflicts: true);
    }

    private function assertBelongsToBooking(VenueBookingExtensionRequest $extension, VenueBooking $booking): void
    {
        if ($extension->venue_booking_id !== $booking->id) {
            throw new VenueBookingTransitionException('Запрос продления не относится к этой брони.', 'EXTENSION_BOOKING_MISMATCH');
        }
    }
}
