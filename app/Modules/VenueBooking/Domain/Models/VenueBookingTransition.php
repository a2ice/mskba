<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'venue_booking_id', 'from_status', 'to_status', 'actor_id', 'reason',
    'metadata', 'booking_version',
])]
class VenueBookingTransition extends Model
{
    public const UPDATED_AT = null;

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Booking transition history is immutable.'));
        static::deleting(static fn () => throw new LogicException('Booking transition history is immutable.'));
    }

    protected function casts(): array
    {
        return [
            'from_status' => VenueBookingStatusEnum::class,
            'to_status' => VenueBookingStatusEnum::class,
            'metadata' => 'array',
            'booking_version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
