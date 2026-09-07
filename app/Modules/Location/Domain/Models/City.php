<?php

namespace App\Modules\Location\Domain\Models;

use App\Modules\Location\Infrastructure\Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'alias',
    'short_name',
    'description',
])]
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    protected static function newFactory(): CityFactory
    {
        return CityFactory::new();
    }

    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }
}
