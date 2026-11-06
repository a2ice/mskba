<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

final class VenueSearchCache
{
    private const VERSION_KEY = 'venue-search:version';

    /** @return array<int, array<string, mixed>> */
    public function documents(): array
    {
        $version = $this->version();

        return $this->repository()->remember(
            "venue-search:index:v{$version}",
            (int) config('venue_search.index_ttl_seconds', 3600),
            fn (): array => $this->buildDocuments(),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function buildDocuments(): array
    {
        $venues = Venue::query()->with([
            'tags:id,venue_id,name,slug',
            'location.address.cityDirectory',
            'location.metroStations:id,name',
            'courts',
        ])->orderBy('id')->get();

        $policies = VenueBookingPolicy::query()
            ->whereIn('venue_id', $venues->pluck('id')->all())
            ->where('active_marker', true)
            ->where('is_enabled', true)
            ->get()->keyBy('venue_id');

        $discovery = app(VenueCourtDiscoveryAccess::class);

        return $venues->map(function (Venue $venue) use ($policies, $discovery): array {
                    $address = $venue->location?->address;
                    $metroModels = $venue->location?->metroStations ?? collect();
                    $metros = $metroModels->pluck('name')->values()->all();
                    $metroIds = $metroModels->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
                    $tags = $venue->tags->pluck('name')->values()->all();
                    $tagSlugs = $venue->tags->pluck('slug')->values()->all();

                    $courtAccess = $discovery->classify($venue, $policies->get($venue->id));

                    return [
                        'court_access' => $courtAccess,
                        'id' => $venue->id,
                        'name' => $venue->name,
                        'alias' => $venue->alias,
                        'type' => $venue->type->label(),
                        'type_slug' => $venue->type->value,
                        'status' => $venue->status->label(),
                        'status_slug' => $venue->status->value,
                        'operational_status' => $venue->operational_status->value,
                        'requires_payment' => $venue->requires_payment,
                        'requires_booking_approval' => $venue->requires_booking_approval,
                        'short_description' => $venue->short_description,
                        'raw_address' => $venue->raw_address,
                        'city' => mb_strtolower(trim((string) ($address?->cityDirectory?->name ?? $address?->city ?? ''))),
                        'street' => mb_strtolower(trim((string) ($address?->street ?? ''))),
                        'latitude' => $address?->latitude === null ? null : (float) $address->latitude,
                        'longitude' => $address?->longitude === null ? null : (float) $address->longitude,
                        'metro_stations' => $metros,
                        'metro_station_ids' => $metroIds,
                        'tags' => $tags,
                        'search_text' => mb_strtolower(implode(' ', array_filter([
                            $venue->name,
                            $venue->raw_address,
                            $venue->short_description,
                            $venue->courts->pluck('name')->implode(' '),
                            implode(' ', $metros),
                            implode(' ', $tags),
                            implode(' ', $tagSlugs),
                        ]))),
                    ];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function rememberResult(array $parameters, Closure $resolver): array
    {
        $version = $this->version();
        $hash = sha1(json_encode($parameters, JSON_THROW_ON_ERROR));

        return $this->repository()->remember(
            "venue-search:result:v{$version}:{$hash}",
            (int) config('venue_search.result_ttl_seconds', 60),
            $resolver,
        );
    }

    public function invalidate(): void
    {
        $repository = $this->repository();

        if (! $repository->has(self::VERSION_KEY)) {
            $repository->forever(self::VERSION_KEY, 2);

            return;
        }

        $repository->increment(self::VERSION_KEY);
    }

    private function version(): int
    {
        return (int) $this->repository()->rememberForever(self::VERSION_KEY, fn (): int => 1);
    }

    private function repository(): Repository
    {
        $store = config('venue_search.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
