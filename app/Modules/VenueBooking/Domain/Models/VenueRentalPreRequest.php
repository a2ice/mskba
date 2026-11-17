<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Enums\VenueRentalPreRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['public_id', 'venue_id', 'venue_court_id', 'requester_user_id', 'created_by_actor_id',
    'idempotency_key', 'payload_hash', 'scope', 'starts_at', 'ends_at', 'note', 'status', 'resolved_at', 'withdrawn_at'])]
class VenueRentalPreRequest extends Model
{
    use Auditable;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class)->withTrashed();
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class, 'venue_court_id')->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id')->withTrashed();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(VenueBooking::class, 'pre_request_id');
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(VenueBookingConversation::class, 'pre_request_id');
    }

    protected function casts(): array
    {
        return ['venue_id' => 'integer', 'venue_court_id' => 'integer', 'requester_user_id' => 'integer',
            'status' => VenueRentalPreRequestStatus::class, 'scope' => VenueBookingScopeEnum::class,
            'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];
    }
}
