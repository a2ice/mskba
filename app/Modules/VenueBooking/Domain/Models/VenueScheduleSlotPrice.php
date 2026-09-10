<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'venue_id', 'day_of_week', 'starts_at',
    'whole_price_per_step_minor', 'half_price_per_step_minor',
])]
final class VenueScheduleSlotPrice extends Model
{
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'whole_price_per_step_minor' => 'integer',
            'half_price_per_step_minor' => 'integer',
        ];
    }
}
