<?php

namespace App\Modules\VenueBooking\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'message_id', 'venue_booking_id', 'event_type', 'payload', 'status',
    'attempts', 'available_at', 'processing_started_at', 'published_at', 'last_error',
])]
class VenueBookingOutboxMessage extends Model
{
    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }
}
