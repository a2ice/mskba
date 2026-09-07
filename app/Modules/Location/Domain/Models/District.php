<?php

namespace App\Modules\Location\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Location\Infrastructure\Database\Factories\DistrictFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'city_id',
    'name',
    'alias',
    'short_name',
    'description',
])]
class District extends Model
{
    /** @use HasFactory<DistrictFactory> */
    use Auditable, HasFactory;

    protected static function newFactory(): DistrictFactory
    {
        return DistrictFactory::new();
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }
}
