<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Exceptions\VenuePendingModerationException;
use App\Modules\Venue\Domain\Models\Venue;

final class ShowEditableVenueHandler
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
                'tags',
                'media' => fn ($query) => $query->where('collection', 'gallery')->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('id'),
                'draftRevision.media' => fn ($query) => $query->where('collection', 'gallery'),
                'moderationRequests' => fn ($query) => $query
                    ->with('messages')
                    ->latest('id')
                    ->limit(5),
            ])
            ->whereRouteIdentifier($alias)
            ->orderBy('id')
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

        return $venue;
    }
}
