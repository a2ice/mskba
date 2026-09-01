<?php

namespace App\Modules\VenueBooking\Infrastructure\Jobs;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\VenueBooking\Application\UseCases\ExpireVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Ramsey\Uuid\Uuid;
use Throwable;

final class ExpireVenueBookingIfDueJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [5, 20, 60, 180];

    public function __construct(
        public readonly int $bookingId,
        public readonly int $observedVersion,
        public readonly string $observedDeadline,
    ) {}

    public function handle(ExpireVenueBookingHandler $expire, CurrentActorResolver $actors): void
    {
        $booking = VenueBooking::query()->find($this->bookingId);
        if (! $this->stillDue($booking)) {
            Cache::increment('metrics:venue_booking:expiry:stale');

            return;
        }

        try {
            $expire->handle(
                $this->bookingId,
                $actors->system(),
                $this->observedVersion,
                $this->idempotencyKey(),
                $this->idempotencyKey(),
            );
            Cache::increment('metrics:venue_booking:expiry:completed');
        } catch (VenueBookingTransitionException $exception) {
            if (in_array($exception->errorCode, [
                'BOOKING_VERSION_CONFLICT', 'HOLD_ACTIVE', 'INVALID_BOOKING_TRANSITION',
            ], true) || str_contains($exception->getMessage(), 'Переход из состояния')) {
                Cache::increment('metrics:venue_booking:expiry:stale');

                return;
            }

            Cache::increment('metrics:venue_booking:expiry:failed');
            throw $exception;
        } catch (Throwable $exception) {
            Cache::increment('metrics:venue_booking:expiry:failed');
            throw $exception;
        }
    }

    private function stillDue(?VenueBooking $booking): bool
    {
        return $booking !== null
            && $booking->status === VenueBookingStatusEnum::HELD
            && $booking->optimistic_version === $this->observedVersion
            && $booking->effective_protection_until !== null
            && $booking->effective_protection_until->equalTo(CarbonImmutable::parse($this->observedDeadline))
            && ! now()->lessThan($booking->effective_protection_until);
    }

    private function idempotencyKey(): string
    {
        return Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            "mskba:venue-booking:expire:{$this->bookingId}:{$this->observedVersion}:{$this->observedDeadline}",
        )->toString();
    }
}
