<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class VenueBookingConflictMetrics
{
    public function record(VenueBooking $candidate, int $conflictsCount): void
    {
        Cache::increment('metrics:venue_booking:conflicts');
        Log::info('venue_booking_conflict', [
            'venue_id' => $candidate->venue_id,
            'booking_id' => $candidate->id,
            'scope' => $candidate->scope->value,
            'conflicts_count' => $conflictsCount,
        ]);
    }
}
