<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'public_id', 'venue_id', 'policy_version_id', 'quoted_for_user_id', 'scope',
    'starts_at', 'ends_at', 'amount_minor', 'currency', 'hold_duration_minutes',
    'payment_window_minutes', 'requires_payment', 'snapshot', 'valid_until',
])]
class VenueBookingQuote extends Model
{
    public const UPDATED_AT = null;

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(VenueBookingPolicy::class, 'policy_version_id');
    }

    public function quotedFor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quoted_for_user_id');
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Booking quote is immutable.'));
        static::deleting(static fn () => throw new LogicException('Booking quote is immutable.'));
    }

    protected function casts(): array
    {
        return [
            'scope' => VenueBookingScopeEnum::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'amount_minor' => 'integer',
            'hold_duration_minutes' => 'integer',
            'payment_window_minutes' => 'integer',
            'requires_payment' => 'boolean',
            'snapshot' => 'array',
            'valid_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
