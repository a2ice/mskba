<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingExtensionStatus;
use App\Modules\VenueBooking\Domain\Events\VenueBookingExtensionRequested;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingExtensionRequest;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RequestVenueBookingExtensionHandler
{
    public function __construct(
        private LockedVenueBooking $lockedBooking,
        private FeatureFlags $features,
    ) {}

    public function handle(int $bookingId, Actor $actor, CarbonImmutable $requestedUntil, string $reason): VenueBookingExtensionRequest
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking) use ($actor, $requestedUntil, $reason): VenueBookingExtensionRequest {
            if ($actor->user_id === null || $actor->user_id !== $booking->requester_user_id) {
                throw new VenueBookingTransitionException('Только заявитель может запросить продление.', 'BOOKING_FORBIDDEN');
            }

            $now = CarbonImmutable::now();
            if ($booking->status !== VenueBookingStatusEnum::HELD
                || $booking->effective_protection_until === null
                || ! $now->lessThan($booking->effective_protection_until)) {
                throw new VenueBookingTransitionException('Запросить продление можно только во время действующего удержания.', 'HOLD_EXPIRED');
            }

            $this->assertWithinPolicy($booking, $requestedUntil);

            if (VenueBookingExtensionRequest::query()->where('venue_booking_id', $booking->id)->where('active_marker', true)->exists()) {
                throw new VenueBookingTransitionException('По брони уже ожидает решения запрос на продление.', 'EXTENSION_ALREADY_PENDING');
            }

            $extension = VenueBookingExtensionRequest::query()->create([
                'public_id' => (string) Str::uuid(),
                'venue_booking_id' => $booking->id,
                'requested_by_actor_id' => $actor->id,
                'previous_deadline_at' => $booking->effective_protection_until,
                'requested_until' => $requestedUntil,
                'reason' => trim($reason),
                'status' => VenueBookingExtensionStatus::PENDING,
                'active_marker' => true,
                'requested_at' => $now,
            ]);

            DB::afterCommit(static fn () => event(new VenueBookingExtensionRequested($booking->id, $extension->id)));

            return $extension;
        });
    }

    public static function assertWithinPolicy(VenueBooking $booking, CarbonImmutable $requestedUntil): void
    {
        $allows = (bool) data_get($booking->quote_snapshot, 'policy.allows_hold_extension', false);
        $limit = (int) data_get($booking->quote_snapshot, 'policy.maximum_hold_extension_minutes', 0);
        if (! $allows || $limit < 1) {
            throw new VenueBookingTransitionException('Условия брони не разрешают продление.', 'EXTENSION_NOT_ALLOWED');
        }

        if ($booking->effective_protection_until === null || ! $requestedUntil->greaterThan($booking->effective_protection_until)) {
            throw new VenueBookingTransitionException('Новый срок должен быть позже текущего.', 'INVALID_EXTENSION_DEADLINE');
        }

        $maximum = $booking->hold_expires_at?->addMinutes($limit);
        if ($maximum === null || $requestedUntil->greaterThan($maximum)) {
            throw new VenueBookingTransitionException('Запрошенный срок превышает лимит условий брони.', 'EXTENSION_LIMIT_EXCEEDED');
        }
    }
}
