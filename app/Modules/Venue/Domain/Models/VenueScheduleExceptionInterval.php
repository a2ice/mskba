<?php

namespace App\Modules\Venue\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['venue_schedule_exception_id', 'starts_at', 'ends_at', 'sort_order'])]
class VenueScheduleExceptionInterval extends Model
{
    public function exception(): BelongsTo
    {
        return $this->belongsTo(VenueScheduleException::class, 'venue_schedule_exception_id');
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
