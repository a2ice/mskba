<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Venue\Infrastructure\Database\Factories\VenueScheduleIntervalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'venue_schedule_id',
    'day_of_week',
    'starts_at',
    'ends_at',
    'sort_order',
])]
class VenueScheduleInterval extends Model
{
    /** @use HasFactory<VenueScheduleIntervalFactory> */
    use HasFactory;

    protected static function newFactory(): VenueScheduleIntervalFactory
    {
        return VenueScheduleIntervalFactory::new();
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(VenueSchedule::class, 'venue_schedule_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
