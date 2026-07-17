<?php

namespace App\Modules\Venue\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['venue_id', 'name', 'slug'])]
class VenueTag extends Model
{
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
