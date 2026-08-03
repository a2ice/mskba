<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Venue\Domain\Enums\VenueMarkingConditionEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'venue_id',
    'hoops_count',
    'hoops_condition',
    'surface_condition',
    'marking_condition',
])]
final class VenueCharacteristic extends Model
{
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    protected function casts(): array
    {
        return [
            'hoops_count' => 'integer',
            'hoops_condition' => 'integer',
            'surface_condition' => 'integer',
            'marking_condition' => VenueMarkingConditionEnum::class,
        ];
    }
}
