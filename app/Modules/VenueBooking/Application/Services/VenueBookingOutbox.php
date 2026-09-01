<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\VenueBooking\Domain\Models\VenueBookingOutboxMessage;
use App\Modules\VenueBooking\Infrastructure\Jobs\DispatchVenueBookingOutboxJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VenueBookingOutbox
{
    /** @param class-string $eventType
     * @param  array<string, mixed>  $payload
     */
    public function record(int $bookingId, string $eventType, array $payload = []): VenueBookingOutboxMessage
    {
        $message = VenueBookingOutboxMessage::query()->create([
            'message_id' => (string) Str::uuid(),
            'venue_booking_id' => $bookingId,
            'event_type' => $eventType,
            'payload' => ['booking_id' => $bookingId, ...$payload],
            'status' => 'pending',
            'available_at' => now(),
        ]);

        DB::afterCommit(static fn () => DispatchVenueBookingOutboxJob::dispatch($message->id));

        return $message;
    }
}
