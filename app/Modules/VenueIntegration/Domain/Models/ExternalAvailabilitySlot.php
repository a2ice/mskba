<?php

namespace App\Modules\VenueIntegration\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'external_availability_snapshot_id', 'local_date', 'starts_at', 'ends_at',
    'status', 'price_minor', 'currency', 'price_unit_minutes', 'day_complete',
])]
final class ExternalAvailabilitySlot extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('External availability slots are immutable.');
        });
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ExternalAvailabilitySnapshot::class, 'external_availability_snapshot_id');
    }

    protected function casts(): array
    {
        return [
            'local_date' => 'immutable_date',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'price_minor' => 'integer',
            'price_unit_minutes' => 'integer',
            'day_complete' => 'boolean',
        ];
    }
}
