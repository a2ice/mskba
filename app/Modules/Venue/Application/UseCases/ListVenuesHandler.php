<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Application\Services\RecommendVenuesForIntent;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use Illuminate\Support\Collection;
use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Venue\Application\Builders\ListVenuesBuilder;
use App\Modules\Venue\Application\DTO\VenueListItemDTO;
use App\Modules\Venue\Application\DTO\VenueMetroStationDTO;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Application\Services\VenueProximityService;
use App\Modules\Venue\Application\Services\VenueCourtDiscoveryAccess;
use App\Modules\Venue\Application\Services\VenueTypeFilter;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;

final class ListVenuesHandler
{
    public function __construct(
        private readonly VenueAccessResolver $accessResolver,
        private readonly ListVenuesBuilder $listVenuesBuilder,
        private readonly AddressDisplayFormatter $addressFormatter,
        private readonly VenueCourtDiscoveryAccess $courtDiscovery,
        private readonly VenueProximityService $proximity,
        private readonly RecommendVenuesForIntent $interestMatcher,
    ) {}

    /** @param array{search?: string|null, city_id?: int|string|null, district_id?: int|string|null, type?: string|array|null, operational_status?: string|null, access?: string|null, sort?: string|null, has_upcoming_events?: bool|int|string|null, event_days?: int|string|null, latitude?: float|string|null, longitude?: float|string|null, radius_km?: int|string|null} $filters */
    /** @param Collection<int, PlayIntent>|null $matchingInterests */
    public function handle(?User $user, ?Actor $actor = null, array $filters = [], ?Collection $matchingInterests = null, ?array $onlyVenueIds = null, bool $configuredRentalOnly = false, ?array $distanceOrigin = null): array
    {
        $contractViewableVenueIds = $this->accessResolver->contractViewableVenueIdsFor($user);
        $contractEditableVenueIds = $this->accessResolver->contractEditableVenueIdsFor($user);
        $contractScheduleEditableVenueIds = $this->accessResolver->contractScheduleEditableVenueIdsFor($user);
        $bootstrapOwnedVenueIds = $this->accessResolver->bootstrapOwnedVenueIdsFor($user);
        $actorOwnedVenueIds = $this->accessResolver->actorOwnedVenueIdsFor($actor);

        $types = VenueTypeFilter::values($filters['type'] ?? null);
        $venues = $this->listVenuesBuilder->build(function ($query) use ($contractViewableVenueIds, $bootstrapOwnedVenueIds, $actorOwnedVenueIds, $filters, $onlyVenueIds, $types): void {
            // Hidden venues are managed through Filament/account rather than
            // published in cards, map pins or search, even for their owners.
            $query->when($onlyVenueIds !== null, fn ($subset) => $subset->whereIn('id', $onlyVenueIds))
                ->where('is_published', true)->where(function ($visible) use ($contractViewableVenueIds, $bootstrapOwnedVenueIds, $actorOwnedVenueIds): void {
                $visible->where('status', VenueStatusEnum::CONFIRMED->value)
                    ->orWhereIn('id', $contractViewableVenueIds)
                    ->orWhereIn('id', $bootstrapOwnedVenueIds)
                    ->orWhereIn('id', $actorOwnedVenueIds);
            });

            $query
                ->when($filters['search'] ?? null, function ($filtered, string $search): void {
                    $terms = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

                    foreach ($terms as $term) {
                        $needle = '%'.addcslashes($term, '%_\\').'%';
                        $filtered->where(fn ($match) => $match
                            ->whereLike('name', $needle)
                            ->orWhereLike('raw_address', $needle)
                            ->orWhereLike('short_description', $needle));
                    }
                })
                ->when(isset($filters['city_id']) || isset($filters['district_id']), function ($filtered) use ($filters): void {
                    $filtered->whereHas('location.address', function ($address) use ($filters): void {
                        $address->when(isset($filters['city_id']), fn ($query) => $query->where('city_id', (int) $filters['city_id']))
                            ->when(isset($filters['district_id']), fn ($query) => $query->where('district_id', (int) $filters['district_id']));
                    });
                })
                ->when($types !== [], function ($filtered) use ($types): void {
                    $filtered->where(function ($any) use ($types): void {
                        foreach ($types as $type) {
                            $any->orWhere(fn ($branch) => $branch->whereDiscoveryType($type));
                        }
                    });
                })
                ->when((bool) ($filters['has_upcoming_events'] ?? false), function ($filtered) use ($filters): void {
                    $now = now();
                    $until = $now->copy()->addDays((int) ($filters['event_days'] ?? 7));
                    $filtered->whereHas('events', fn ($events) => $events
                        ->where('status', EventStatusEnum::PUBLISHED->value)
                        ->where('visibility', EventVisibilityEnum::PUBLIC->value)
                        ->whereBetween('starts_at', [$now, $until]));
                });
        })
            ->with('courts.media')
            ->when(
                in_array($filters['sort'] ?? null, ['new', 'old'], true),
                fn ($query) => $query->orderBy('created_at', ($filters['sort'] ?? null) === 'old' ? 'asc' : 'desc')->orderBy('id', ($filters['sort'] ?? null) === 'old' ? 'asc' : 'desc'),
                function ($query) use ($filters): void {
                    $direction = ($filters['sort'] ?? null) === 'name_desc' ? 'desc' : 'asc';
                    $query->orderBy('name', $direction)->orderBy('id');
                },
            )
            ->get();

        // Keep the same access-aware result for cards, map and later pages.
        // Do not use the nearby-preview handler here: it limits the result to 50.
        if (isset($filters['latitude'], $filters['longitude'], $filters['radius_km'])) {
            $latitude = (float) $filters['latitude'];
            $longitude = (float) $filters['longitude'];
            $radiusMeters = (int) $filters['radius_km'] * 1000;

            $venues = $venues->filter(fn (Venue $venue): bool =>
                ($this->proximity->distanceToCoordinates($venue, $latitude, $longitude) ?? INF) <= $radiusMeters);
        }

        // Apply the same subscription eligibility policy used by the existing
        // matching service, before the catalog count, pagination and map mode.
        if ($matchingInterests !== null) {
            $venues = $venues->filter(fn (Venue $venue): bool =>
                $matchingInterests->contains(fn (PlayIntent $intent): bool =>
                    $this->interestMatcher->matchesVenue($intent, $venue)));
        }

        // Apply distance to the complete eligible catalogue before any pagination.
        // Missing coordinates remain visible, after every geocoded venue.
        $sort = $filters['sort'] ?? null;
        if (in_array($sort, ['rating_asc', 'rating_desc'], true)) {
            $venues = $venues->sort(function (Venue $a, Venue $b) use ($sort): int {
                $left = $a->published_reviews_avg_rating;
                $right = $b->published_reviews_avg_rating;
                if ($left === null || $right === null) {
                    return ($left === null) <=> ($right === null);
                }

                return (round((float) $left, 1) <=> round((float) $right, 1)) * ($sort === 'rating_desc' ? -1 : 1);
            })->values();
        }
        if (in_array($sort, ['distance', 'distance_desc'], true) && $distanceOrigin !== null) {
            $distances = $venues->mapWithKeys(fn (Venue $venue) => [$venue->id =>
                $this->proximity->distanceToCoordinates(
                    $venue, $distanceOrigin['latitude'], $distanceOrigin['longitude'],
                )]);
            $venues = $venues->sort(function (Venue $a, Venue $b) use ($distances, $sort): int {
                $left = $distances->get($a->id);
                $right = $distances->get($b->id);
                if ($left === null || $right === null) {
                    return ($left === null) <=> ($right === null);
                }

                return ($left <=> $right) * ($sort === 'distance_desc' ? -1 : 1);
            })->values();
        }

        $policies = VenueBookingPolicy::query()
            ->whereIn('venue_id', $venues->pluck('id')->all())
            ->where('active_marker', true)
            ->where('is_enabled', true)
            ->get()
            ->keyBy('venue_id');

        $accessFilter = $filters['access'] ?? null;
        $environments = [];
        $passportTypes = [];
        foreach ($types as $type) {
            $environment = VenueTypeEnum::from($type)->discoveryEnvironment();
            if ($environment !== null) {
                $environments[] = $environment->value;
            } else {
                $passportTypes[] = $type;
            }
        }
        $statusFilter = $filters['operational_status'] ?? null;

        return $venues
            ->map(function (Venue $venue) use ($policies, $accessFilter, $environments, $passportTypes, $statusFilter, $configuredRentalOnly): array {
                $access = $this->courtDiscovery->classify($venue, $policies->get($venue->id), includeClosed: true, configuredRentalOnly: $configuredRentalOnly);
                $passportMatch = in_array($venue->type->value, $passportTypes, true);
                $requiresCourt = $configuredRentalOnly || filled($accessFilter);
                // A selected passport type is an OR branch; access and rental
                // still have to match a real court of that same venue.
                $candidates = $environments === [] || ($passportMatch && $requiresCourt) ? [null] : $environments;
                $matches = [];
                foreach ($candidates as $environment) {
                    $match = VenueCourtDiscoveryAccess::select(
                        $access,
                        $environment,
                        VenueCourtDiscoveryAccess::paymentFilter($accessFilter),
                        $accessFilter === 'approval' ? true : null,
                        $statusFilter,
                        allowUnconstrained: $configuredRentalOnly,
                    );
                    if ($match !== null) {
                        $matches[] = $match;
                    }
                }
                usort($matches, fn (array $a, array $b): int =>
                    ($b['is_open'] <=> $a['is_open'])
                    ?: ($a['sort_order'] <=> $b['sort_order'])
                    ?: ($a['court_id'] <=> $b['court_id']));
                $matched = $matches[0] ?? null;
                $eligible = $matched !== null || (! $requiresCourt
                    && ($environments === [] || $passportMatch)
                    && ($statusFilter === null || $venue->operational_status->value === $statusFilter));

                return ['venue' => $venue, 'access' => $access, 'matched' => $matched, 'eligible' => $eligible];
            })
            ->filter(fn (array $row): bool => $row['eligible'])
            ->map(function (array $row) use (
                $contractViewableVenueIds, $contractEditableVenueIds,
                $contractScheduleEditableVenueIds, $bootstrapOwnedVenueIds, $actorOwnedVenueIds,
            ) {
                /** @var Venue $venue */
                $venue = $row['venue'];
                $access = $row['access'];
                $matched = $row['matched'];
                $isBootstrapOwned = in_array($venue->id, $bootstrapOwnedVenueIds, true);
                $isActorOwned = in_array($venue->id, $actorOwnedVenueIds, true);

                return new VenueListItemDTO(
                    id: $venue->id,
                    rating: $venue->published_reviews_avg_rating === null ? null : round((float) $venue->published_reviews_avg_rating, 1),
                    name: $venue->name,
                    alias: $venue->alias,
                    type: $venue->type->label(),
                    typeSlug: $venue->type->value,
                    operationalStatus: $venue->operational_status->label(),
                    operationalStatusSlug: $venue->operational_status->value,
                    requiresPayment: $venue->requires_payment,
                    requiresBookingApproval: $venue->requires_booking_approval,
                    status: $venue->status->label(),
                    statusSlug: $venue->status->value,
                    shortDescription: $venue->short_description,
                    rawAddress: $venue->raw_address,
                    displayAddress: trim((string) $venue->raw_address) !== ''
                        ? $this->addressFormatter->format($venue->raw_address)
                        : $this->addressFormatter->format(
                            $venue->location?->address?->full_address,
                            $venue->location?->address?->city,
                            $venue->location?->address?->street,
                            $venue->location?->address?->building,
                        ),
                    imageUrl: $venue->representativePhoto()?->publicUrl(),
                    latitude: $venue->location?->address?->latitude !== null ? (float) $venue->location->address->latitude : null,
                    longitude: $venue->location?->address?->longitude !== null ? (float) $venue->location->address->longitude : null,
                    canView: $venue->status === VenueStatusEnum::CONFIRMED || $isBootstrapOwned || $isActorOwned || in_array($venue->id, $contractViewableVenueIds, true),
                    canEdit: $venue->allowsDetailsEditing() && ($isBootstrapOwned || $isActorOwned || in_array($venue->id, $contractEditableVenueIds, true)),
                    canEditSchedule: $venue->allowsOperationalChanges() && ($isBootstrapOwned || $isActorOwned || in_array($venue->id, $contractScheduleEditableVenueIds, true)),
                    canRemove: $isBootstrapOwned || $isActorOwned || in_array($venue->id, $contractEditableVenueIds, true),
                    courtAccessSummary: $access,
                    matchedCourtUrl: $matched['url'] ?? null,
                    matchedCourtName: $matched['name'] ?? null,
                    matchedRequiresBooking: $matched['requires_booking'] ?? null,
                    matchedRequiresApproval: $matched['requires_booking_approval'] ?? null,
                    metroStations: ($venue->location?->metroStations ?? collect())
                        ->map(fn ($station) => new VenueMetroStationDTO(
                            id: (int) $station->id,
                            name: $station->name,
                            lineName: $station->line?->name,
                            lineColor: $station->line?->color,
                            latitude: $station->latitude === null ? null : (string) $station->latitude,
                            longitude: $station->longitude === null ? null : (string) $station->longitude,
                            distanceMeters: $station->pivot?->distance_meters === null ? null : (int) $station->pivot->distance_meters,
                            walkingTimeMinutes: $station->pivot?->walking_time_minutes === null ? null : (int) $station->pivot->walking_time_minutes,
                        ))->values()->all(),
                );
            })
            ->all();
    }
}
