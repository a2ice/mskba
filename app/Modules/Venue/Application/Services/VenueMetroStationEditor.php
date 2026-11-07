<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits station membership without granting access to geographic/passport fields.
 * A location may be reused by other entities: never mutate the old pivot in place.
 */
final readonly class VenueMetroStationEditor
{
    public function __construct(private VenueRevisionManager $revisions) {}

    /** @param list<int> $stationIds */
    public function update(Venue $venue, ?Actor $actor, array $stationIds): void
    {
        DB::transaction(function () use ($venue, $actor, $stationIds): void {
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            if (! $venue->allowsOperationalChanges() || $venue->hasPendingModerationRequest()) {
                throw ValidationException::withMessages([
                    'metro_station_ids' => 'Площадка сейчас недоступна для изменения метро.',
                ]);
            }

            if ($venue->status === VenueStatusEnum::CONFIRMED) {
                $revision = $this->revisions->getOrCreateDraft($venue, $actor);
                $this->revisions->assertCurrent($revision);
                $payload = $revision->payload;
                $payload['location'] = array_replace(
                    (array) ($payload['location'] ?? []),
                    ['metro_station_ids' => $stationIds],
                );
                $revision->forceFill(['payload' => $payload])->save();

                return;
            }

            $previous = $venue->location;
            $location = $previous?->replicate() ?? new Location();
            $location->save();
            $location->metroStations()->sync($stationIds);
            $venue->location()->associate($location);
            $venue->save();
            $venue->increment('content_version');
        });
    }
}
