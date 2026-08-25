<?php

namespace App\Modules\VenueBooking\Domain\Services;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;

final class VenueBookingLifecycle
{
    public function recordRequested(VenueBooking $booking, Actor $actor): void
    {
        $booking->transitions()->create([
            'from_status' => null,
            'to_status' => VenueBookingStatusEnum::REQUESTED,
            'actor_id' => $actor->id,
            'booking_version' => $booking->optimistic_version,
            'metadata' => [],
        ]);
    }

    public function hold(VenueBooking $booking, Actor $actor, CarbonImmutable $now, ?int $expectedVersion = null): void
    {
        $this->assertVersion($booking, $expectedVersion);
        $this->assertStatus($booking, VenueBookingStatusEnum::REQUESTED);
        $holdMinutes = (int) data_get($booking->quote_snapshot, 'policy.hold_duration_minutes', 0);

        if ($holdMinutes < 1) {
            throw new VenueBookingTransitionException('В расчёте отсутствует допустимый срок удержания.', 'INVALID_QUOTE_SNAPSHOT');
        }

        $deadline = $now->addMinutes($holdMinutes);
        $this->transition($booking, VenueBookingStatusEnum::HELD, $actor, null, [
            'held_at' => $now,
            'hold_expires_at' => $deadline,
            'effective_protection_until' => $deadline,
        ]);
    }

    public function reject(VenueBooking $booking, Actor $actor, CarbonImmutable $now, ?string $reason = null, ?int $expectedVersion = null): void
    {
        $this->assertVersion($booking, $expectedVersion);
        $this->assertStatus($booking, VenueBookingStatusEnum::REQUESTED);
        $this->transition($booking, VenueBookingStatusEnum::REJECTED, $actor, $reason, ['terminal_at' => $now]);
    }

    public function cancel(VenueBooking $booking, Actor $actor, CarbonImmutable $now, ?string $reason = null, ?int $expectedVersion = null): void
    {
        $this->assertVersion($booking, $expectedVersion);

        if (! in_array($booking->status, [
            VenueBookingStatusEnum::REQUESTED,
            VenueBookingStatusEnum::HELD,
            VenueBookingStatusEnum::CONFIRMED,
        ], true)) {
            throw new VenueBookingTransitionException('Эту бронь уже нельзя отменить.');
        }

        if ($booking->status === VenueBookingStatusEnum::CONFIRMED) {
            $beforeMinutes = data_get($booking->quote_snapshot, 'policy.cancellation_before_minutes');
            if ($beforeMinutes === null || $now->greaterThan($booking->starts_at->subMinutes((int) $beforeMinutes))) {
                throw new VenueBookingTransitionException('Срок допустимой отмены подтверждённой брони истёк.', 'CANCELLATION_WINDOW_CLOSED');
            }
        }

        $this->transition($booking, VenueBookingStatusEnum::CANCELLED, $actor, $reason, [
            'terminal_at' => $now,
            'hold_expires_at' => null,
            'effective_protection_until' => null,
        ]);
    }

    public function confirm(VenueBooking $booking, Actor $actor, CarbonImmutable $now, ?int $expectedVersion = null): void
    {
        $this->assertVersion($booking, $expectedVersion);
        $this->assertStatus($booking, VenueBookingStatusEnum::HELD);

        if ($booking->effective_protection_until === null || ! $now->lessThan($booking->effective_protection_until)) {
            throw new VenueBookingTransitionException('Срок удержания истёк.', 'HOLD_EXPIRED');
        }

        $requiresPayment = (bool) data_get($booking->quote_snapshot, 'policy.requires_payment', false);
        if ($requiresPayment && $booking->payment_state !== VenueBookingPaymentState::CONFIRMED) {
            throw new VenueBookingTransitionException('Оплата ещё не подтверждена.', 'PAYMENT_NOT_CONFIRMED');
        }

        $this->transition($booking, VenueBookingStatusEnum::CONFIRMED, $actor, null, [
            'confirmed_at' => $now,
            'hold_expires_at' => null,
            'effective_protection_until' => null,
        ]);
    }

    public function expire(VenueBooking $booking, Actor $actor, CarbonImmutable $now, ?int $expectedVersion = null): void
    {
        $this->assertVersion($booking, $expectedVersion);
        $this->assertStatus($booking, VenueBookingStatusEnum::HELD);

        if ($booking->effective_protection_until === null || $now->lessThan($booking->effective_protection_until)) {
            throw new VenueBookingTransitionException('Срок удержания ещё не истёк.', 'HOLD_ACTIVE');
        }

        $this->transition($booking, VenueBookingStatusEnum::EXPIRED, $actor, 'Истёк срок удержания.', [
            'terminal_at' => $now,
            'hold_expires_at' => null,
            'effective_protection_until' => null,
            'payment_state' => $booking->payment_state === VenueBookingPaymentState::NOT_REQUIRED
                ? VenueBookingPaymentState::NOT_REQUIRED
                : VenueBookingPaymentState::EXPIRED,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function transition(VenueBooking $booking, VenueBookingStatusEnum $to, Actor $actor, ?string $reason, array $attributes): void
    {
        $from = $booking->status;
        $nextVersion = $booking->optimistic_version + 1;
        $booking->applyLifecycleTransition([
            ...$attributes,
            'status' => $to,
            'optimistic_version' => $nextVersion,
        ]);
        $booking->transitions()->create([
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'metadata' => [],
            'booking_version' => $nextVersion,
        ]);
    }

    private function assertStatus(VenueBooking $booking, VenueBookingStatusEnum $expected): void
    {
        if ($booking->status !== $expected) {
            throw new VenueBookingTransitionException("Переход из состояния {$booking->status->value} недоступен.");
        }
    }

    private function assertVersion(VenueBooking $booking, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $booking->optimistic_version !== $expectedVersion) {
            throw new VenueBookingTransitionException('Бронь уже была изменена. Обновите данные.', 'BOOKING_VERSION_CONFLICT');
        }
    }
}
