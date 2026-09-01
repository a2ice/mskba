<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingExtensionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id', 'venue_booking_id', 'requested_by_actor_id', 'previous_deadline_at',
    'requested_until', 'reason', 'status', 'active_marker', 'reviewed_by_actor_id',
    'decision_reason', 'requested_at', 'decided_at', 'cancelled_at',
])]
class VenueBookingExtensionRequest extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    public function requestedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'requested_by_actor_id');
    }

    public function reviewedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'reviewed_by_actor_id');
    }

    protected function casts(): array
    {
        return [
            'previous_deadline_at' => 'immutable_datetime',
            'requested_until' => 'immutable_datetime',
            'status' => VenueBookingExtensionStatus::class,
            'active_marker' => 'boolean',
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
