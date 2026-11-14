<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_venue_interest_id', 'initiated_by_actor_id', 'booking_id',
    'event_confirmation_version', 'status', 'quoted_amount_minor',
    'currency', 'responded_at',
])]
final class EventVenueBookingProposal extends Model
{
    public function interest(): BelongsTo
    {
        return $this->belongsTo(EventVenueInterest::class, 'event_venue_interest_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'initiated_by_actor_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'booking_id');
    }

    protected function casts(): array
    {
        return [
            'event_confirmation_version' => 'integer',
            'quoted_amount_minor' => 'integer',
            'responded_at' => 'immutable_datetime',
        ];
    }
}
