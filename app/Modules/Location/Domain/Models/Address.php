<?php

namespace App\Modules\Location\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Location\Infrastructure\Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'postal_code',
    'city_id',
    'district_id',
    'city',
    'street',
    'building',
    'latitude',
    'longitude',
    'full_address',
])]
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use Auditable, HasFactory;

    protected static function newFactory(): AddressFactory
    {
        return AddressFactory::new();
    }

    public function cityDirectory(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}
