<?php

namespace App\Modules\Venue\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'venue_contract_id',
    'permission',
])]
class VenueContractPermission extends Model
{
    public function venueContract(): BelongsTo
    {
        return $this->belongsTo(VenueContract::class);
    }
}
