<?php

namespace App\Modules\VenueIntegration\Domain\Models;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'venue_integration_source_id', 'venue_id', 'venue_court_id', 'created_by_user_id',
    'external_court_id', 'provider', 'timezone', 'parser_version',
    'checksum', 'snapshot_key', 'currency', 'step_minutes', 'minimum_minutes',
    'freshness_ttl_seconds', 'complete', 'observed_at', 'coverage_start_at',
    'coverage_end_at', 'diagnostic',
])]
final class ExternalAvailabilitySnapshot extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('External availability snapshots are immutable.');
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(VenueIntegrationSource::class, 'venue_integration_source_id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class, 'venue_court_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(ExternalAvailabilitySlot::class);
    }

    public function isStale(): bool
    {
        return $this->observed_at->addSeconds($this->freshness_ttl_seconds)->lessThan(now());
    }

    protected function casts(): array
    {
        return [
            'step_minutes' => 'integer',
            'minimum_minutes' => 'integer',
            'freshness_ttl_seconds' => 'integer',
            'complete' => 'boolean',
            'observed_at' => 'immutable_datetime',
            'coverage_start_at' => 'immutable_datetime',
            'coverage_end_at' => 'immutable_datetime',
            'diagnostic' => 'array',
        ];
    }
}
