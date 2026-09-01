<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\BookingContributionAccess;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Domain\Enums\BookingContributionStatus;
use App\Modules\VenueBooking\Domain\Events\ContributionCommitmentWithdrawn;
use App\Modules\VenueBooking\Domain\Models\BookingContributionCommitment;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;

final readonly class WithdrawContributionCommitmentHandler
{
    public function __construct(private LockedVenueBooking $lockedBooking, private BookingContributionAccess $access, private FeatureFlags $features) {}

    public function handle(int $bookingId, Actor $actor): ?BookingContributionCommitment
    {
        $this->features->ensureEnabled(VenueRentalFeature::CONTRIBUTIONS);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking) use ($actor): ?BookingContributionCommitment {
            $this->access->assertCanContribute($actor, $booking);
            $commitment = BookingContributionCommitment::query()
                ->where('venue_booking_id', $booking->id)
                ->where('user_id', $actor->user->canonical()->id)
                ->where('active_marker', true)
                ->lockForUpdate()
                ->first();
            if ($commitment === null) {
                return null;
            }

            $commitment->forceFill([
                'status' => BookingContributionStatus::WITHDRAWN,
                'active_marker' => null,
                'withdrawn_at' => now(),
            ])->save();
            DB::afterCommit(static fn () => event(new ContributionCommitmentWithdrawn($booking->id, $commitment->id)));

            return $commitment;
        });
    }
}
