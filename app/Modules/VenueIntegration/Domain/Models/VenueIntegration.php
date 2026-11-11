<?php

namespace App\Modules\VenueIntegration\Domain\Models;

use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'venue_id', 'name', 'description', 'timezone', 'freshness_ttl_seconds',
    'horizon_days', 'refresh_on_visit', 'scheduled_refresh', 'mode', 'enabled',
    'version', 'created_by_user_id', 'updated_by_user_id',
])]
final class VenueIntegration extends Model
{
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(VenueIntegrationSource::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(VenueIntegrationAuditEvent::class);
    }

    protected function casts(): array
    {
        return [
            'freshness_ttl_seconds' => 'integer',
            'horizon_days' => 'integer',
            'refresh_on_visit' => 'boolean',
            'scheduled_refresh' => 'boolean',
            'enabled' => 'boolean',
            'version' => 'integer',
        ];
    }
}
