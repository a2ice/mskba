<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPartyRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['venue_booking_id', 'user_id', 'role'])]
class VenueBookingParty extends Model
{
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
        return ['role' => VenueBookingPartyRole::class];
    }
}
