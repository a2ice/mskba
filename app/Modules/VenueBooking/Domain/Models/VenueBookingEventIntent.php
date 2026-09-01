<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['venue_booking_id', 'created_by_actor_id', 'request_key', 'event_payload', 'telegram_chat_ids'])]
final class VenueBookingEventIntent extends Model
{
    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    public function creatorActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Venue booking event intent is immutable.'));
    }

    protected function casts(): array
    {
        return [
            'event_payload' => 'array',
            'telegram_chat_ids' => 'array',
        ];
    }
}
