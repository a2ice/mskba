<?php

namespace App\Modules\Venue\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['venue_court_id', 'weekly_intervals', 'exceptions'])]
final class VenueCourtSchedule extends Model
{
    public function court(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class, 'venue_court_id');
    }

    protected function casts(): array
    {
        return [
            'weekly_intervals' => 'array',
            'exceptions' => 'array',
        ];
    }
}
