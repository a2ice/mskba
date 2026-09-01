<?php

namespace App\Modules\Coordination\Domain\Models;

use App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceResponseValue;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['round_id', 'user_id', 'response', 'responded_at'])]
final class VenueBookingAttendanceResponse extends Model
{
    public function round(): BelongsTo
    {
        return $this->belongsTo(VenueBookingAttendanceRound::class, 'round_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'response' => VenueBookingAttendanceResponseValue::class,
            'responded_at' => 'immutable_datetime',
        ];
    }
}
