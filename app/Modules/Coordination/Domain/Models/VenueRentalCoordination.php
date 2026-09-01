<?php

namespace App\Modules\Coordination\Domain\Models;

use App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id', 'organizer_actor_id', 'organizer_user_id', 'venue_id',
    'venue_booking_id', 'title', 'description', 'status', 'visibility',
    'participants_visibility', 'scope', 'starts_at', 'ends_at', 'closed_at', 'converted_at',
])]
class VenueRentalCoordination extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organizerActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_user_id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(VenueRentalCoordinationParticipant::class, 'coordination_id');
    }

    protected function casts(): array
    {
        return [
            'status' => VenueRentalCoordinationStatus::class,
            'scope' => VenueBookingScopeEnum::class,
            'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime', 'converted_at' => 'immutable_datetime',
        ];
    }
}
