<?php

namespace App\Modules\Location\Domain\Models;

use App\Modules\Location\Infrastructure\Database\Factories\MetroStationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'metro_line_id',
    'name',
    'latitude',
    'longitude',
    'sort_order',
])]
class MetroStation extends Model
{
    /** @use HasFactory<MetroStationFactory> */
    use HasFactory;

    protected static function newFactory(): MetroStationFactory
    {
        return MetroStationFactory::new();
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(MetroLine::class, 'metro_line_id');
    }

    public function locations(): BelongsToMany
    {
        return $this
            ->belongsToMany(Location::class, 'location_metro_station')
            ->withPivot(['distance_meters', 'walking_time_minutes'])
            ->withTimestamps();
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
