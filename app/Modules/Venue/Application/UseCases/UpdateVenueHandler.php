<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Application\Services\VenueDetailsUpdater;
use App\Modules\Venue\Application\Services\VenueRevisionManager;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Exceptions\VenuePendingModerationException;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

final class UpdateVenueHandler
{
    public function __construct(
        private readonly VenueAccessResolver $access,
        private readonly VenueDetailsUpdater $updater,
        private readonly VenueRevisionManager $revisions,
    ) {}

    /**
     * @param  array{name: string, type: string, short_description?: string|null, full_description?: string|null, raw_address?: string|null}  $data
     */
    public function handle(string $alias, ?User $user, ?Actor $actor, array $data, CreateLocationDTO $locationData, array $tagNames = []): Venue
    {
        return DB::transaction(function () use ($alias, $user, $actor, $data, $locationData, $tagNames): Venue {
            $venues = Venue::query()
                ->with('creatorActor')
                ->whereRouteIdentifier($alias)
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

            if ($venue->hasPendingModerationRequest()) {
                throw new VenuePendingModerationException;
            }

            if ($venue->status === VenueStatusEnum::CONFIRMED) {
                $this->revisions->saveDetails($venue, $actor, $data, $locationData, $tagNames);

                return $venue->refresh();
            }

            return $this->updater->update($venue, $data, $locationData, $tagNames);
        });
    }
}
