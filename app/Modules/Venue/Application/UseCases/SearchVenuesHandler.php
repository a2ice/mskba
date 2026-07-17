<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\DTO\VenueListItemDTO;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;

final readonly class SearchVenuesHandler
{
    public function __construct(
        private ListVenuesHandler $listVenues,
    ) {}

    /**
     * @return array<int, VenueListItemDTO>
     */
    public function handle(
        ?User $user,
        ?Actor $actor,
        ?string $query = null,
        ?VenueTypeEnum $type = null,
        ?int $metroStationId = null,
        int $limit = 20,
    ): array {
        $venues = collect($this->listVenues->handle($user, $actor));

        if ($type !== null) {
            $venues = $venues->filter(
                fn (VenueListItemDTO $venue): bool => $venue->type === $type->label(),
            );
        }

        $needle = mb_strtolower(trim((string) $query));

        if ($needle !== '') {
            $venues = $venues->filter(function (VenueListItemDTO $venue) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $venue->name,
                    $venue->shortDescription,
                    $venue->rawAddress,
                ])));

                return str_contains($haystack, $needle);
            });
        }

        if ($metroStationId !== null) {
            $venueIds = $venues->pluck('id')->all();
            $metroVenueIds = Venue::query()
                ->whereIn('id', $venueIds)
                ->whereHas('location.metroStations', fn ($query) => $query->whereKey($metroStationId))
                ->pluck('id')
                ->all();

            $venues = $venues->whereIn('id', $metroVenueIds);
        }

        return $venues
            ->take(max(1, min($limit, 50)))
            ->values()
            ->all();
    }
}
