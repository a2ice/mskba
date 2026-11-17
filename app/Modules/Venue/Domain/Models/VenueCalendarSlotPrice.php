<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'venue_id', 'date', 'starts_at',
    'whole_price_per_step_minor', 'half_price_per_step_minor',
])]
final class VenueCalendarSlotPrice extends Model
{
    use Auditable;

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date:Y-m-d',
            'whole_price_per_step_minor' => 'integer',
            'half_price_per_step_minor' => 'integer',
        ];
    }
}
