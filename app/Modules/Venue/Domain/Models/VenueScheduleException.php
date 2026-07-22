<?php

namespace App\Modules\Venue\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['venue_schedule_id', 'date', 'is_closed'])]
class VenueScheduleException extends Model
{
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(VenueSchedule::class, 'venue_schedule_id');
    }

    public function intervals(): HasMany
    {
        return $this->hasMany(VenueScheduleExceptionInterval::class)->orderBy('sort_order')->orderBy('starts_at');
    }

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'is_closed' => 'boolean'];
    }
}
