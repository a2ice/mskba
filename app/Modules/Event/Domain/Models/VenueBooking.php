<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'venue_id',
    'venue_court_id',
    'event_id',
    'created_by_actor_id',
    'status',
    'scope',
    'starts_at',
    'ends_at',
])]
class VenueBooking extends Model
{
    use Auditable;

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class, 'venue_court_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * During the booking-first migration an Event can be linked through
     * events.booking_id even when an older booking row still has event_id=null.
     * Keep the legacy relation compatible with both directions until the old
     * projection can be retired.
     */
    public function getRelationValue($key)
    {
        $value = parent::getRelationValue($key);

        if ($key !== 'event' || $value !== null || ! $this->exists) {
            return $value;
        }

        $event = Event::query()
            ->with('primaryGame')
            ->where('booking_id', $this->getKey())
            ->first();

        if ($event !== null) {
            $this->setRelation('event', $event);
        }

        return $event;
    }

    public function creatorActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    protected function casts(): array
    {
        return [
            'venue_court_id' => 'integer',
            'status' => VenueBookingStatusEnum::class,
            'scope' => VenueBookingScopeEnum::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }
}
