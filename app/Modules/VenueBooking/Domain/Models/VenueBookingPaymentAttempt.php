<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id', 'venue_booking_id', 'amount_minor', 'currency', 'method', 'provider',
    'provider_reference', 'provider_idempotency_key', 'merchant_reference', 'provider_metadata', 'provider_checked_at',
    'payment_instructions', 'status', 'window_opened_at', 'window_expires_at',
    'opened_by_actor_id', 'claimed_by_actor_id', 'evidence_metadata', 'claimed_at',
    'reviewed_by_actor_id', 'review_reason', 'reviewed_at', 'expired_at',
])]
class VenueBookingPaymentAttempt extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    public function openedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'opened_by_actor_id');
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => VenueBookingPaymentState::class,
            'window_opened_at' => 'immutable_datetime',
            'window_expires_at' => 'immutable_datetime',
            'evidence_metadata' => 'array',
            'provider_metadata' => 'array',
            'provider_checked_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
        ];
    }
}
