<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Contract\Domain\Models\Contract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'contract_id',
    'venue_id',
])]
class VenueContract extends Model
{
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(VenueContractPermission::class);
    }
}
