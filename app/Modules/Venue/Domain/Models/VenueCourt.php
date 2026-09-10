<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Venue\Domain\Enums\VenueSurfaceTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'venue_id',
    'name',
    'alias',
    'sort_order',
    'is_primary',
    'supports_halves',
    'hoops_count',
    'surface_type',
    'allows_whole',
    'allows_halves',
])]
final class VenueCourt extends Model
{
    use SoftDeletes;

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function routeIdentifier(): string
    {
        return $this->id.'-'.$this->alias;
    }

    /**
     * @param  Builder<VenueCourt>  $query
     * @return Builder<VenueCourt>
     */
    public function scopeWhereRouteIdentifier(Builder $query, string $identifier): Builder
    {
        if (ctype_digit($identifier)) {
            return $query->whereKey((int) $identifier);
        }

        if (preg_match('/^(\d+)-/', $identifier, $matches) === 1) {
            return $query->whereKey((int) $matches[1]);
        }

        return $query->where('alias', $identifier);
    }

    public function allowsScope(bool $whole): bool
    {
        return $whole ? $this->allows_whole : ($this->supports_halves && $this->allows_halves);
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
            'supports_halves' => 'boolean',
            'hoops_count' => 'integer',
            'surface_type' => VenueSurfaceTypeEnum::class,
            'allows_whole' => 'boolean',
            'allows_halves' => 'boolean',
        ];
    }
}
