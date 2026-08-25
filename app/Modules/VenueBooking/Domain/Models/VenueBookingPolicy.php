<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'venue_id', 'version', 'is_enabled', 'allows_whole', 'allows_halves',
    'minimum_duration_minutes', 'maximum_duration_minutes', 'time_step_minutes',
    'minimum_lead_time_minutes', 'maximum_advance_days', 'currency',
    'whole_price_per_step_minor', 'half_price_per_step_minor',
    'hold_duration_minutes', 'requires_payment', 'payment_window_minutes',
    'quote_validity_minutes', 'cancellation_before_minutes',
    'published_by_user_id', 'published_at', 'active_marker',
])]
class VenueBookingPolicy extends Model
{
    use Auditable;

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(VenueBookingQuote::class, 'policy_version_id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_enabled' => 'boolean',
            'allows_whole' => 'boolean',
            'allows_halves' => 'boolean',
            'minimum_duration_minutes' => 'integer',
            'maximum_duration_minutes' => 'integer',
            'time_step_minutes' => 'integer',
            'minimum_lead_time_minutes' => 'integer',
            'maximum_advance_days' => 'integer',
            'whole_price_per_step_minor' => 'integer',
            'half_price_per_step_minor' => 'integer',
            'hold_duration_minutes' => 'integer',
            'requires_payment' => 'boolean',
            'payment_window_minutes' => 'integer',
            'quote_validity_minutes' => 'integer',
            'cancellation_before_minutes' => 'integer',
            'published_at' => 'datetime',
            'active_marker' => 'boolean',
        ];
    }
}
