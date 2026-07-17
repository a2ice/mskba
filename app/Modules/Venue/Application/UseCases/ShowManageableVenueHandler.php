<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Models\Venue;

final class ShowManageableVenueHandler
{
    public function __construct(
        private readonly VenueAccessResolver $access,
    ) {}

    public function handle(string $alias, ?User $user, ?Actor $actor = null): Venue
    {
        $venues = Venue::query()
            ->with([
                'canonicalVenue',
                'creatorActor',
                'location.metroStations',
                'moderationRequests' => fn ($query) => $query
                    ->with('messages.sender.user')
                    ->latest('id'),
            ])
            ->where('alias', $alias)
            ->orderBy('id')
            ->get();

        if ($venues->isEmpty()) {
            throw new VenueNotFoundException;
        }

        $venue = $venues->first(fn (Venue $venue): bool => $this->access->canManage($user, $venue, $actor));

        if ($venue === null) {
            throw new VenueAccessDeniedException;
        }

        return $venue;
    }
}
