<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Domain\Enums\VenueSurfaceTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
    'environment',
    'allows_whole',
    'allows_halves',
    'operational_status',
    'requires_payment',
    'requires_booking',
    'requires_booking_approval',
    'rental_enabled',
    'whole_price_per_step_minor',
    'half_price_per_step_minor',
])]
final class VenueCourt extends Model
{
    use SoftDeletes;

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function schedule(): HasOne
    {
        return $this->hasOne(VenueCourtSchedule::class, 'venue_court_id');
    }

    public function slotPrices(): HasMany
    {
        return $this->hasMany(VenueCourtSlotPrice::class);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
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
            'environment' => \App\Modules\Venue\Domain\Enums\VenueCourtEnvironmentEnum::class,
            'allows_whole' => 'boolean',
            'allows_halves' => 'boolean',
            'operational_status' => VenueOperationalStatusEnum::class,
            'requires_payment' => 'boolean',
            'requires_booking' => 'boolean',
            'requires_booking_approval' => 'boolean',
            'rental_enabled' => 'boolean',
            'whole_price_per_step_minor' => 'integer',
            'half_price_per_step_minor' => 'integer',
        ];
    }
}
