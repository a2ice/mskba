<?php

namespace App\Modules\VenueBooking\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['public_id', 'venue_booking_id'])]
class VenueBookingConversation extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(VenueBookingMessage::class, 'conversation_id');
    }

    public function readMarkers(): HasMany
    {
        return $this->hasMany(VenueBookingConversationRead::class, 'conversation_id');
    }
}
