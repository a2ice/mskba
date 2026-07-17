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
        ?bool $requiresPayment = null,
        ?bool $requiresBookingApproval = null,
        int $limit = 20,
    ): array {
        $venues = collect($this->listVenues->handle($user, $actor));

        if ($type !== null) {
            $venues = $venues->filter(
                fn (VenueListItemDTO $venue): bool => $venue->type === $type->label(),
            );
        }

        if ($requiresPayment !== null || $requiresBookingApproval !== null) {
            $conditionVenueIds = Venue::query()
                ->whereIn('id', $venues->pluck('id')->all())
                ->when($requiresPayment !== null, fn ($query) => $query->where('requires_payment', $requiresPayment))
                ->when($requiresBookingApproval !== null, fn ($query) => $query->where('requires_booking_approval', $requiresBookingApproval))
                ->pluck('id')
                ->all();

            $venues = $venues->whereIn('id', $conditionVenueIds);
        }

        $needle = trim((string) $query);

        if ($needle !== '') {
            $visibleVenueIds = $venues->pluck('id')->all();
            $matchingVenueIds = Venue::query()
                ->whereIn('id', $visibleVenueIds)
                ->where(function ($query) use ($needle): void {
                    $query
                        ->where('name', 'like', '%'.$needle.'%')
                        ->orWhere('short_description', 'like', '%'.$needle.'%')
                        ->orWhere('raw_address', 'like', '%'.$needle.'%')
                        ->orWhereHas('tags', fn ($query) => $query->where('name', 'like', '%'.$needle.'%'))
                        ->orWhereHas('location.metroStations', fn ($query) => $query->where('name', 'like', '%'.$needle.'%'));
                })
                ->pluck('id')
                ->all();

            $venues = $venues->whereIn('id', $matchingVenueIds);
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
