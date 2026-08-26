<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\VenueBooking\Domain\Enums\BookingContributionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id', 'venue_booking_id', 'user_id', 'amount_minor', 'currency', 'status',
    'active_marker', 'share_with_organizer', 'payment_intent_reference', 'committed_at', 'withdrawn_at',
])]
class BookingContributionCommitment extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer', 'status' => BookingContributionStatus::class,
            'active_marker' => 'boolean', 'share_with_organizer' => 'boolean',
            'committed_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime',
        ];
    }
}
