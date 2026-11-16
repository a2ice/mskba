<?php

namespace App\Modules\VenueBooking\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'venue_booking_id', 'venue_integration_source_id', 'confirmed_by_actor_id',
    'method', 'reference', 'note', 'confirmed_at', 'expires_at',
])]
final class VenueBookingExternalHold extends Model
{
    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
