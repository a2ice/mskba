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
        private readonly VenueUniquenessChecker $uniqueness,
    ) {}

    public function detectFor(Venue $venue): void
    {
        $venue->loadMissing('location.address');

        $this->candidatesFor($venue)
            ->each(function (Venue $candidate) use ($venue): void {
                $matchedBy = $this->matchedBy($venue, $candidate);

                if ($matchedBy === null) {
                    return;
                }

                [$venueId, $duplicateVenueId] = $this->orderedPair((int) $venue->id, (int) $candidate->id);

                VenueDuplicate::query()->updateOrCreate(
                    [
                        'venue_id' => $venueId,
                        'duplicate_venue_id' => $duplicateVenueId,
                    ],
                    [
                        'matched_by' => $matchedBy,
                        'status' => VenueDuplicateStatusEnum::PENDING,
                        'score' => $matchedBy === VenueDuplicateMatchTypeEnum::NAME_AND_ADDRESS ? 100 : 70,
                        'metadata' => [
                            'source_venue_id' => (int) $venue->id,
                            'candidate_venue_id' => (int) $candidate->id,
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
        return Venue::query()
            ->with('location.address')
            ->whereKeyNot($venue->id)
            ->where('status', VenueStatusEnum::UNCONFIRMED)
            ->whereNull('canonical_venue_id')
            ->get();
    }

    private function matchedBy(Venue $venue, Venue $candidate): ?VenueDuplicateMatchTypeEnum
    {
        $nameMatches = mb_strtolower((string) $venue->alias) === mb_strtolower((string) $candidate->alias);
        $addressMatches = $this->uniqueness->venuesShareAddress($venue, $candidate);

        return match (true) {
            $nameMatches && $addressMatches => VenueDuplicateMatchTypeEnum::NAME_AND_ADDRESS,
            $nameMatches => VenueDuplicateMatchTypeEnum::NAME,
            $addressMatches => VenueDuplicateMatchTypeEnum::ADDRESS,
            default => null,
        };
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
