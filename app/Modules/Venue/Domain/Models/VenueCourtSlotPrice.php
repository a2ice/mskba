<?php

namespace App\Modules\Venue\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'venue_court_id', 'day_of_week', 'starts_at',
    'whole_price_per_step_minor', 'half_price_per_step_minor',
])]
final class VenueCourtSlotPrice extends Model
{
    public function court(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class, 'venue_court_id');
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
