<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Venue\Infrastructure\Database\Factories\VenueScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'venue_id',
    'timezone',
])]
class VenueSchedule extends Model
{
    /** @use HasFactory<VenueScheduleFactory> */
    use Auditable, HasFactory;

    protected static function newFactory(): VenueScheduleFactory
    {
        return VenueScheduleFactory::new();
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function intervals(): HasMany
    {
        return $this
            ->hasMany(VenueScheduleInterval::class)
            ->orderBy('day_of_week')
            ->orderBy('sort_order')
            ->orderBy('starts_at');
    }
}
