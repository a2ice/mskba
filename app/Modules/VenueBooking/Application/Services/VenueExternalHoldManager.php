<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Domain\Events\VenueBookingHeld;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingExternalHold;
use App\Modules\VenueBooking\Domain\Services\VenueBookingLifecycle;
use App\Modules\VenueIntegration\Domain\Models\VenueCourtExternalScheduleImport;
use Carbon\CarbonImmutable;

final readonly class VenueExternalHoldManager
{
    public function __construct(
        private LockedVenueBooking $locked,
        private VenueBookingAuthorization $authorization,
        private VenueBookingLifecycle $lifecycle,
        private VenueBookingOutbox $outbox,
    ) {}

    public static function required(VenueBooking $booking): bool
    {
        return (bool) data_get($booking->quote_snapshot, 'policy.external_hold_required', false);
    }

    public function confirmedDeadline(VenueBooking $booking): ?CarbonImmutable
    {
        if (! self::required($booking)) {
            return null;
        }
        $hold = VenueBookingExternalHold::query()->where('venue_booking_id', $booking->id)->first();
        if ($hold === null || $hold->expires_at === null || ! now()->lessThan($hold->expires_at)) {
            throw new VenueBookingTransitionException(
                'Реальный холд площадки не подтверждён или истёк.', 'EXTERNAL_HOLD_REQUIRED',
            );
        }

        return CarbonImmutable::instance($hold->expires_at);
    }

    public function assertActive(VenueBooking $booking): void
    {
        $this->confirmedDeadline($booking);
    }

    /** Manual attestation from the venue, agent or aggregator: never invent an external booking. */
    public function confirm(
        int $bookingId,
        Actor $actor,
        CarbonImmutable $expiresAt,
        string $method,
        ?string $reference,
        ?string $note,
        ?int $expectedVersion = null,
    ): VenueBooking {
        if (! config('features.venue_rental.external_holds', false)) {
            throw new VenueBookingTransitionException('Внешние холды не включены.', 'FEATURE_DISABLED');
        }
        if (! in_array($method, ['phone', 'messenger', 'api', 'aggregator', 'other'], true)) {
            throw new VenueBookingTransitionException('Некорректный способ подтверждения.', 'INVALID_HOLD_METHOD');
        }
        if ($method !== 'api' && trim((string) $note) === '' && trim((string) $reference) === '') {
            throw new VenueBookingTransitionException('Укажите основание подтверждения.', 'HOLD_EVIDENCE_REQUIRED');
        }

        return $this->locked->run($bookingId, function (VenueBooking $booking, $venue) use (
            $actor, $expiresAt, $method, $reference, $note, $expectedVersion,
        ): VenueBooking {
            $this->authorization->assertCanConfirmBooking($actor, $venue, $booking);
            if (! self::required($booking)) {
                throw new VenueBookingTransitionException('Для этого зала внешний холд не требуется.', 'EXTERNAL_HOLD_NOT_REQUIRED');
            }
            if ($booking->status !== VenueBookingStatusEnum::REQUESTED
                || ($expectedVersion !== null && $booking->optimistic_version !== $expectedVersion)) {
                throw new VenueBookingTransitionException('Заявка уже изменена.', 'BOOKING_VERSION_CONFLICT');
            }
            $now = CarbonImmutable::now();
            $maxMinutes = (int) data_get($booking->quote_snapshot, 'policy.hold_duration_minutes', 0);
            if ($maxMinutes < 1 || ! $expiresAt->greaterThan($now)
                || $expiresAt->greaterThan($now->addMinutes($maxMinutes))) {
                throw new VenueBookingTransitionException(
                    'Срок подтверждённого холда должен быть в пределах условий площадки.', 'EXTERNAL_HOLD_DEADLINE',
                );
            }

            $sourceId = $booking->venue_court_id === null ? null
                : VenueCourtExternalScheduleImport::query()
                    ->where('venue_court_id', $booking->venue_court_id)
                    ->value('venue_integration_source_id');
            VenueBookingExternalHold::query()->updateOrCreate(
                ['venue_booking_id' => $booking->id],
                [
                    'venue_integration_source_id' => $sourceId,
                    'confirmed_by_actor_id' => $actor->id,
                    'method' => $method,
                    'reference' => $reference,
                    'note' => $note,
                    'confirmed_at' => $now,
                    'expires_at' => $expiresAt,
                ],
            );
            $this->lifecycle->hold($booking, $actor, $now, $expectedVersion, $expiresAt);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'auditable_type' => VenueBooking::class,
                'auditable_id' => $booking->id,
                'event' => 'external_hold_confirmed',
                'old_values' => [],
                'new_values' => [
                    'expires_at' => $expiresAt->toIso8601String(),
                    'method' => $method,
                ],
                'metadata' => [
                    'source_id' => $sourceId, 'reference' => $reference, 'note' => $note,
                ],
            ]);
            $this->outbox->record($booking->id, VenueBookingHeld::class);
            if ((bool) data_get($booking->quote_snapshot, 'policy.invoice_workflow', false)) {
                app(VenueRentalInvoiceManager::class)->autoIssueBuyerWithinLock($booking, $actor);
            }

            return $booking->fresh(['transitions', 'externalHold']);
        }, lockConflicts: true);
    }
}
