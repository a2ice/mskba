<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus;
use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationConverted;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ConvertVenueRentalCoordinationToBookingHandler
{
    public function __construct(
        private FeatureFlags $features,
        private QuoteVenueBookingHandler $quotes,
        private RequestVenueBookingHandler $requests,
    ) {}

    public function handle(int $id, Actor $actor, string $idempotencyKey): VenueBooking
    {
        $this->features->ensureEnabled(VenueRentalFeature::COORDINATION);

        return DB::transaction(function () use ($id, $actor, $idempotencyKey): VenueBooking {
            $coordination = VenueRentalCoordination::query()->with('venue')->lockForUpdate()->findOrFail($id);
            if ($coordination->organizer_actor_id !== $actor->id) {
                throw new InvalidArgumentException('Перейти к аренде может только организатор.');
            }
            if ($coordination->venue_booking_id !== null) {
                return VenueBooking::query()->findOrFail($coordination->venue_booking_id);
            }
            if (! in_array($coordination->status, [VenueRentalCoordinationStatus::OPEN, VenueRentalCoordinationStatus::CLOSED], true)) {
                throw new InvalidArgumentException('Этот сбор нельзя преобразовать в заявку.');
            }

            $duration = (int) $coordination->starts_at->diffInMinutes($coordination->ends_at);
            $quote = $this->quotes->handle(
                $coordination->venue,
                $coordination->starts_at,
                $duration,
                $coordination->scope,
                $actor->user,
            );
            $booking = $this->requests->handle(
                $actor,
                $quote->publicId,
                $idempotencyKey,
                $coordination->public_id,
            );
            $coordination->update([
                'venue_booking_id' => $booking->id,
                'status' => VenueRentalCoordinationStatus::CONVERTED,
                'closed_at' => $coordination->closed_at ?? now(),
                'converted_at' => now(),
            ]);
            DB::afterCommit(static fn () => event(new VenueRentalCoordinationConverted($coordination->id, $booking->id)));

            return $booking;
        });
    }
}
