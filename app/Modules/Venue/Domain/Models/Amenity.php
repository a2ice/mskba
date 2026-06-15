<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Venue\Infrastructure\Database\Factories\AmenityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'alias',
    'description',
    'icon',
    'is_active',
    'sort_order',
])]
class Amenity extends Model
{
    /** @use HasFactory<AmenityFactory> */
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): AmenityFactory
    {
        return AmenityFactory::new();
    }

    public function venues(): BelongsToMany
    {
        return $this
            ->belongsToMany(Venue::class, 'venue_amenities')
            ->withPivot(['note', 'deleted_at'])
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
