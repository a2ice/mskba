<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\BookingContributionAccess;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use App\Modules\VenueBooking\Domain\Enums\BookingContributionStatus;
use App\Modules\VenueBooking\Domain\Events\ContributionCommitmentSet;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\BookingContributionCommitment;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class SetContributionCommitmentHandler
{
    public function __construct(
        private LockedVenueBooking $lockedBooking,
        private BookingContributionAccess $access,
        private MinorAmountParser $amounts,
        private FeatureFlags $features,
    ) {}

    public function handle(int $bookingId, Actor $actor, string $amount, bool $shareWithOrganizer): BookingContributionCommitment
    {
        $this->features->ensureEnabled(VenueRentalFeature::CONTRIBUTIONS);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking) use ($actor, $amount, $shareWithOrganizer): BookingContributionCommitment {
            $this->access->assertCanContribute($actor, $booking);
            $this->assertOpen($booking);

            $currency = strtoupper((string) data_get($booking->quote_snapshot, 'pricing.currency', ''));
            $target = (int) data_get($booking->quote_snapshot, 'pricing.amount_minor', -1);
            $minor = $this->amounts->parse($amount, $currency);
            if ($target < 1 || $minor < 1 || $minor > $target) {
                throw new VenueBookingTransitionException('Сумма должна быть больше нуля и не превышать стоимость аренды.', 'INVALID_CONTRIBUTION_AMOUNT');
            }

            $userId = $actor->user->canonical()->id;
            $active = BookingContributionCommitment::query()
                ->where('venue_booking_id', $booking->id)
                ->where('user_id', $userId)
                ->where('active_marker', true)
                ->lockForUpdate()
                ->first();
            if ($active !== null && $active->amount_minor === $minor && $active->share_with_organizer === $shareWithOrganizer) {
                return $active;
            }

            if ($active !== null) {
                $active->forceFill([
                    'status' => BookingContributionStatus::REPLACED,
                    'active_marker' => null,
                    'withdrawn_at' => now(),
                ])->save();
            }

            $commitment = BookingContributionCommitment::query()->create([
                'public_id' => (string) Str::uuid(),
                'venue_booking_id' => $booking->id,
                'user_id' => $userId,
                'amount_minor' => $minor,
                'currency' => $currency,
                'status' => BookingContributionStatus::ACTIVE,
                'active_marker' => true,
                'share_with_organizer' => $shareWithOrganizer,
                'committed_at' => now(),
            ]);

            DB::afterCommit(static fn () => event(new ContributionCommitmentSet($booking->id, $commitment->id)));

            return $commitment;
        });
    }

    private function assertOpen(VenueBooking $booking): void
    {
        $open = ($booking->status === VenueBookingStatusEnum::HELD
                && $booking->effective_protection_until !== null
                && now()->lessThan($booking->effective_protection_until))
            || ($booking->status === VenueBookingStatusEnum::CONFIRMED && now()->lessThan($booking->starts_at));
        if (! $open) {
            throw new VenueBookingTransitionException('Сбор обещаний по этой брони уже закрыт.', 'CONTRIBUTIONS_CLOSED');
        }
    }
}
