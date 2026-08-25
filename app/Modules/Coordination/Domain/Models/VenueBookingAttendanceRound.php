<?php

namespace App\Modules\Coordination\Domain\Models;

use App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceRoundStatus;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id', 'venue_booking_id', 'created_by_actor_id', 'status', 'active_marker',
    'responses_visibility', 'deadline_at', 'minimum_yes_responses', 'yes_count',
    'no_count', 'maybe_count', 'pending_count', 'threshold_reached_at', 'closed_at',
    'close_reason',
])]
final class VenueBookingAttendanceRound extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    public function creatorActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(VenueBookingAttendanceResponse::class, 'round_id');
    }

    protected function casts(): array
    {
        return [
            'status' => VenueBookingAttendanceRoundStatus::class,
            'active_marker' => 'boolean',
            'deadline_at' => 'immutable_datetime',
            'minimum_yes_responses' => 'integer',
            'yes_count' => 'integer',
            'no_count' => 'integer',
            'maybe_count' => 'integer',
            'pending_count' => 'integer',
            'threshold_reached_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
