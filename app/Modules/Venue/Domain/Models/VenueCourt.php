<?php

namespace App\Modules\Venue\Domain\Models;

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

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
            'supports_halves' => 'boolean',
        ];
    }
}
