<?php

namespace App\Modules\VenueIntegration\Domain\Models;

use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'venue_integration_source_id', 'venue_court_id', 'external_court_id',
])]
final class VenueIntegrationCourtMapping extends Model
{
    public function source(): BelongsTo
    {
        return $this->belongsTo(VenueIntegrationSource::class, 'venue_integration_source_id');
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class, 'venue_court_id');
    }
}
