<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'actor_id', 'venue_booking_id', 'command_name', 'idempotency_key',
    'correlation_id', 'payload_hash', 'status', 'response',
])]
class VenueBookingCommandReceipt extends Model
{
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    protected function casts(): array
    {
        return ['response' => 'array'];
    }
}
