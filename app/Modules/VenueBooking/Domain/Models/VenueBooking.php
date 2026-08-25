<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Coordination\Domain\Models\VenueBookingAttendanceRound;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'public_id', 'flow', 'venue_id', 'event_id', 'created_by_actor_id',
    'requester_user_id', 'policy_version_id', 'quote_id', 'quote_snapshot',
    'payment_state', 'status', 'scope', 'starts_at', 'ends_at',
    'hold_expires_at', 'effective_protection_until', 'optimistic_version',
    'requested_at', 'held_at', 'confirmed_at', 'terminal_at',
])]
class VenueBooking extends Model
{
    use Auditable;

    private bool $lifecycleTransitionAuthorized = false;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function creatorActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(VenueBookingPolicy::class, 'policy_version_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(VenueBookingQuote::class, 'quote_id');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(VenueBookingParty::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(VenueBookingTransition::class)->orderBy('booking_version');
    }

    public function attendanceRounds(): HasMany
    {
        return $this->hasMany(VenueBookingAttendanceRound::class, 'venue_booking_id');
    }

    /** @param array<string, mixed> $attributes */
    public function applyLifecycleTransition(array $attributes): void
    {
        $this->lifecycleTransitionAuthorized = true;

        try {
            $this->forceFill($attributes)->save();
        } finally {
            $this->lifecycleTransitionAuthorized = false;
        }
    }

    protected static function booted(): void
    {
        static::updating(function (self $booking): void {
            if ($booking->isDirty('status') && ! $booking->lifecycleTransitionAuthorized) {
                throw new LogicException('Venue booking status may only change through the lifecycle service.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quote_snapshot' => 'array',
            'payment_state' => VenueBookingPaymentState::class,
            'status' => VenueBookingStatusEnum::class,
            'scope' => VenueBookingScopeEnum::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
            'effective_protection_until' => 'immutable_datetime',
            'optimistic_version' => 'integer',
            'requested_at' => 'immutable_datetime',
            'held_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'terminal_at' => 'immutable_datetime',
        ];
    }
}
