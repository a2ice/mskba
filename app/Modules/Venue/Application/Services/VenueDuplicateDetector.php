<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Enums\VenueDuplicateMatchTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueDuplicate;
use Illuminate\Support\Collection;

final class VenueDuplicateDetector
{
    public function __construct(
        private readonly VenueProximityService $proximity,
    ) {}

    public function detectFor(Venue $venue): void
    {
        $venue->loadMissing('location.address');

        $this->candidatesFor($venue)
            ->each(function (Venue $candidate) use ($venue): void {
                $distanceMeters = $this->proximity->distanceBetween($venue, $candidate);

                if ($distanceMeters === null) {
                    return;
                }

                [$venueId, $duplicateVenueId] = $this->orderedPair((int) $venue->id, (int) $candidate->id);

                VenueDuplicate::query()->updateOrCreate(
                    [
                        'venue_id' => $venueId,
                        'duplicate_venue_id' => $duplicateVenueId,
                    ],
                    [
                        'matched_by' => VenueDuplicateMatchTypeEnum::ADDRESS,
                        'status' => VenueDuplicateStatusEnum::PENDING,
                        'score' => $distanceMeters <= $this->proximity->strongRadiusMeters() ? 100 : 70,
                        'metadata' => [
                            'source_venue_id' => (int) $venue->id,
                            'candidate_venue_id' => (int) $candidate->id,
                            'distance_meters' => $distanceMeters,
                            'strong_radius_meters' => $this->proximity->strongRadiusMeters(),
                            'candidate_radius_meters' => $this->proximity->candidateRadiusMeters(),
                        ],
                    ],
                );
            });
    }

    /**
     * @return Collection<int, Venue>
     */
    private function candidatesFor(Venue $venue): Collection
    {
        return $this->proximity
            ->venuesNearVenue(
                $venue,
                $this->proximity->candidateRadiusMeters(),
                [VenueStatusEnum::UNCONFIRMED, VenueStatusEnum::CONFIRMED],
            )
            ->filter(fn (Venue $candidate): bool => $candidate->canonical_venue_id === null)
            ->values();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function orderedPair(int $firstVenueId, int $secondVenueId): array
    {
        return $firstVenueId < $secondVenueId
            ? [$firstVenueId, $secondVenueId]
            : [$secondVenueId, $firstVenueId];
    }
}
