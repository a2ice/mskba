<?php

namespace App\Modules\VenueBooking\Application\Services;

use Closure;
use Illuminate\Support\Facades\DB;

final class VenueBookingEventConsumer
{
    /** @param Closure(): void $callback */
    public function once(string $consumer, string $messageId, Closure $callback): bool
    {
        return DB::transaction(function () use ($consumer, $messageId, $callback): bool {
            $inserted = DB::table('venue_booking_event_consumptions')->insertOrIgnore([
                'consumer' => $consumer,
                'message_id' => $messageId,
                'consumed_at' => now(),
            ]);

            if ($inserted !== 1) {
                return false;
            }

            $callback();

            return true;
        });
    }
}
