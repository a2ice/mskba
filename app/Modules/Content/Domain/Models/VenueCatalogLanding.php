<?php

namespace App\Modules\Content\Domain\Models;

use App\Modules\Content\Application\Services\VenueCatalogLandingCriteria;
use App\Modules\Content\Application\Services\VenueCatalogLandingNavigation;
use App\Modules\Location\Domain\Models\District;
use App\Modules\Venue\Application\Services\VenueTypeFilter;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

#[Fillable([
    'name', 'slug', 'h1', 'city_id', 'district_id', 'type', 'operational_status',
    'access', 'rental_configured', 'intro_text', 'body_text', 'is_active',
    'allow_indexing', 'include_in_sitemap', 'related_landing_ids',
])]
final class VenueCatalogLanding extends Model
{
    protected $attributes = [
        'rental_configured' => false,
        'is_active' => false,
        'allow_indexing' => false,
        'include_in_sitemap' => false,
    ];

    protected function casts(): array
    {
        return [
            'type_choices' => 'array',
            'related_landing_ids' => 'array',
            'city_id' => 'integer',
            'district_id' => 'integer',
            'rental_configured' => 'boolean',
            'is_active' => 'boolean',
            'allow_indexing' => 'boolean',
            'include_in_sitemap' => 'boolean',
        ];
    }

    public function getTypeAttribute(?string $value): string|array|null
    {
        return $this->type_choices ?? $value;
    }

    public function setTypeAttribute(mixed $value): void
    {
        $type = VenueTypeFilter::canonical($value);
        $this->attributes['type'] = is_array($type) ? null : $type;
        $this->type_choices = is_array($type) ? $type : null;
    }

    protected static function booted(): void
    {
        static::saving(function (self $landing): void {
            if (! is_string($landing->slug) || strlen($landing->slug) > 180
                || ! VenueCatalogLandingNavigation::validSlug($landing->slug)) {
                throw new InvalidArgumentException('Invalid landing slug.');
            }

            $criteria = $landing->criteria();
            if ($criteria === []) {
                throw new InvalidArgumentException('A landing needs at least one filter.');
            }
            if (isset($criteria['district_id']) && (! isset($criteria['city_id'])
                || ! District::query()->whereKey($criteria['district_id'])->where('city_id', $criteria['city_id'])->exists())) {
                throw new InvalidArgumentException('District does not belong to the selected city.');
            }

            $landing->active_filter_signature = $landing->is_active
                ? VenueCatalogLandingCriteria::signature($criteria)
                : null;
        });
    }

    /** @return array<string, int|string|array> */
    public function criteria(): array
    {
        return VenueCatalogLandingCriteria::normalize($this->only(VenueCatalogLandingCriteria::KEYS));
    }
}
