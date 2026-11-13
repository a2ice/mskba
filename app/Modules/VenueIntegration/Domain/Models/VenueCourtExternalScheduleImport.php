<?php

namespace App\Modules\VenueIntegration\Domain\Models;

use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'venue_id', 'venue_court_id', 'venue_integration_source_id',
    'external_availability_snapshot_id', 'applied_by_user_id',
    'first_date', 'last_date', 'applied_at',
])]
final class VenueCourtExternalScheduleImport extends Model
{
    public function court(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class, 'venue_court_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ExternalAvailabilitySnapshot::class, 'external_availability_snapshot_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(VenueIntegrationSource::class, 'venue_integration_source_id');
    }

    protected function casts(): array
    {
        return [
            'first_date' => 'immutable_date',
            'last_date' => 'immutable_date',
            'applied_at' => 'immutable_datetime',
        ];
    }
}
