<?php

namespace App\Modules\Media\Infrastructure\Providers;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentEntry;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueRevision;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'profile' => Profile::class,
            'event' => Event::class,
            'game' => Game::class,
            'venue' => Venue::class,
            'venue_revision' => VenueRevision::class,
            'content_item' => ContentItem::class,
            'team' => Team::class,
            'tournament' => Tournament::class,
            'tournament_entry' => TournamentEntry::class,
        ]);
    }
}
