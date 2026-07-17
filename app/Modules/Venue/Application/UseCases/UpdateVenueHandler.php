<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Application\UseCases\CreateLocationHandler;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Application\Services\VenueTagSynchronizer;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

final class UpdateVenueHandler
{
    public function __construct(
        private readonly CreateLocationHandler $createLocation,
        private readonly VenueAccessResolver $access,
        private readonly VenueTagSynchronizer $tagSynchronizer,
    ) {}

    /**
     * @param  array{name: string, type: string, short_description?: string|null, full_description?: string|null, raw_address?: string|null}  $data
     */
    public function handle(string $alias, ?User $user, ?Actor $actor, array $data, CreateLocationDTO $locationData, array $tagNames = []): Venue
    {
        return DB::transaction(function () use ($alias, $user, $actor, $data, $locationData, $tagNames): Venue {
            $venues = Venue::query()
                ->with('creatorActor')
                ->where('alias', $alias)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($venues->isEmpty()) {
                throw new VenueNotFoundException;
            }

            $venue = $venues->first(fn (Venue $venue): bool => $this->access->canEdit($user, $venue, $actor));

            if ($venue === null) {
                throw new VenueAccessDeniedException;
            }

            $location = $this->createLocation->handle($locationData);

            $venue->forceFill([
                'location_id' => $location?->id,
                'name' => $data['name'],
                'type' => VenueTypeEnum::from($data['type'])->value,
                'short_description' => $data['short_description'] ?? null,
                'full_description' => $data['full_description'] ?? null,
                'raw_address' => $locationData->rawAddress ?? $data['raw_address'] ?? null,
            ])->save();

            $this->tagSynchronizer->sync($venue, $tagNames);

            return $venue->refresh();
        });
    }
}
