<?php

namespace App\Modules\Location\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Location\Infrastructure\Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['address_id'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use Auditable, HasFactory;

    protected static function newFactory(): LocationFactory
    {
        return LocationFactory::new();
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function metroStations(): BelongsToMany
    {
        return $this
            ->belongsToMany(MetroStation::class, 'location_metro_station')
            ->withPivot(['distance_meters', 'walking_time_minutes'])
            ->withTimestamps();
    }
}
