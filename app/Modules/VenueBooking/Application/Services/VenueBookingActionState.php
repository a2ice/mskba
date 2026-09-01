<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;

final readonly class VenueBookingActionState
{
    public function __construct(private VenueCommercialAccess $commercialAccess) {}

    /** @return array<string, array{allowed: bool, reason: string|null}> */
    public function for(VenueBooking $booking, Actor $actor, ?bool $canDecideOverride = null): array
    {
        $isRequester = $actor->user_id === $booking->requester_user_id;
        $canDecide = $canDecideOverride ?? ($actor->user !== null
            && $this->commercialAccess->allows($actor->user, $booking->venue, VenuePermissionEnum::DECIDE_BOOKING_REQUESTS));
        $now = CarbonImmutable::now();
        $heldActive = $booking->effective_protection_until !== null
            && $now->lessThan($booking->effective_protection_until);
        $paymentReady = ! (bool) data_get($booking->quote_snapshot, 'policy.requires_payment', false)
            || $booking->payment_state === VenueBookingPaymentState::CONFIRMED;
        $cancellable = in_array($booking->status, [
            VenueBookingStatusEnum::REQUESTED,
            VenueBookingStatusEnum::HELD,
            VenueBookingStatusEnum::CONFIRMED,
        ], true);

        if ($booking->status === VenueBookingStatusEnum::CONFIRMED) {
            $before = data_get($booking->quote_snapshot, 'policy.cancellation_before_minutes');
            $cancellable = $before !== null && $now->lessThanOrEqualTo($booking->starts_at->subMinutes((int) $before));
        }

        return [
            'accept' => $this->state(
                $canDecide && $booking->status === VenueBookingStatusEnum::REQUESTED,
                $canDecide ? 'STATUS_NOT_REQUESTED' : 'BOOKING_FORBIDDEN',
            ),
            'reject' => $this->state(
                $canDecide && $booking->status === VenueBookingStatusEnum::REQUESTED,
                $canDecide ? 'STATUS_NOT_REQUESTED' : 'BOOKING_FORBIDDEN',
            ),
            'cancel' => $this->state(
                ($isRequester || $canDecide) && $cancellable,
                ! ($isRequester || $canDecide) ? 'BOOKING_FORBIDDEN' : 'CANCELLATION_UNAVAILABLE',
            ),
            'confirm' => $this->state(
                $canDecide && $booking->status === VenueBookingStatusEnum::HELD && $heldActive && $paymentReady,
                $this->confirmReason($canDecide, $booking, $heldActive, $paymentReady),
            ),
        ];
    }

    /** @return array{allowed: bool, reason: string|null} */
    private function state(bool $allowed, string $reason): array
    {
        return ['allowed' => $allowed, 'reason' => $allowed ? null : $reason];
    }

    private function confirmReason(bool $canDecide, VenueBooking $booking, bool $heldActive, bool $paymentReady): string
    {
        if (! $canDecide) {
            return 'BOOKING_FORBIDDEN';
        }

        if ($booking->status !== VenueBookingStatusEnum::HELD) {
            return 'STATUS_NOT_HELD';
        }

        if (! $heldActive) {
            return 'HOLD_EXPIRED';
        }

        return $paymentReady ? 'CONFIRM_UNAVAILABLE' : 'PAYMENT_NOT_CONFIRMED';
    }

    /** @param array<string, array{allowed: bool, reason: string|null}> $actions */
    public function primary(array $actions, bool $isRequester): ?string
    {
        $priority = $isRequester ? ['cancel'] : ['accept', 'confirm', 'reject', 'cancel'];

        foreach ($priority as $action) {
            if ($actions[$action]['allowed'] ?? false) {
                return $action;
            }
        }

        return null;
    }
}
