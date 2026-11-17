<?php

namespace App\Modules\Favorites\Infrastructure\Observers;

use App\Modules\Favorites\Application\Services\NotifyFavoriteVenueFollowers;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class FavoriteTournamentObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Tournament $tournament): void
    {
        if (!$tournament->wasRecentlyCreated && !$tournament->wasChanged([
            'default_venue_id', 'status', 'starts_on',
        ])) {
            return;
        }

        app(NotifyFavoriteVenueFollowers::class)->forTournament($tournament);
    }
}
