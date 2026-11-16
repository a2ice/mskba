<?php

namespace App\Modules\VenueBooking\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['public_id', 'venue_booking_id', 'event_venue_interest_id', 'pre_request_id'])]
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

    public function interest(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Event\Domain\Models\EventVenueInterest::class, 'event_venue_interest_id');
    }

    public function preRequest(): BelongsTo
    {
        return $this->belongsTo(VenueRentalPreRequest::class, 'pre_request_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(VenueBookingMessage::class, 'conversation_id');
    }

    public function belongsToBooking(VenueBooking $booking): bool
    {
        return $booking->pre_request_id === null
            ? $this->venue_booking_id === $booking->id
            : $this->pre_request_id === $booking->pre_request_id;
    }

    protected function casts(): array
    {
        return ['pre_request_id' => 'integer', 'venue_booking_id' => 'integer'];
    }

    public function readMarkers(): HasMany
    {
        return $this->hasMany(VenueBookingConversationRead::class, 'conversation_id');
    }
}
