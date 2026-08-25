<?php

namespace App\Modules\Telegram\Domain\Models;

use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'coordination_id', 'venue_booking_id', 'chat_id', 'message_id', 'status',
    'last_error', 'published_at', 'synced_at',
])]
final class TelegramVenueRentalPublication extends Model
{
    public function coordination(): BelongsTo
    {
        return $this->belongsTo(VenueRentalCoordination::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'chat_id');
    }

    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'published_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
