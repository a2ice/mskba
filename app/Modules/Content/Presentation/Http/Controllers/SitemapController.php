<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

final class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $urls = collect([
            $this->entry(url('/')),
            $this->entry(route('news.index')),
            $this->entry(route('venues')),
            $this->entry(route('events.index')),
            $this->entry(route('teams.index')),
            $this->entry(route('tournaments.index')),
            $this->entry(route('sports-sections.index')),
        ]);

        ContentItem::query()
            ->publishedInFeed()
            ->select(['alias', 'updated_at'])
            ->orderBy('id')
            ->each(fn (ContentItem $content) => $urls->push(
                $this->entry(route('news.show', $content->alias), $content->updated_at?->toIso8601String()),
            ));

        Venue::query()
            ->where('status', VenueStatusEnum::CONFIRMED->value)
            ->whereNull('canonical_venue_id')
            ->select(['id', 'alias', 'updated_at'])
            ->orderBy('id')
            ->each(fn (Venue $venue) => $urls->push(
                $this->entry(route('venues.show', $venue->routeIdentifier()), $venue->updated_at?->toIso8601String()),
            ));

        Event::query()
            ->where('visibility', EventVisibilityEnum::PUBLIC->value)
            ->whereIn('status', [
                EventStatusEnum::PUBLISHED->value,
                EventStatusEnum::COMPLETED->value,
            ])
            ->select(['id', 'alias', 'updated_at'])
            ->orderBy('id')
            ->each(fn (Event $event) => $urls->push(
                $this->entry(route('events.show', $event->routeIdentifier()), $event->updated_at?->toIso8601String()),
            ));

        Team::query()
            ->competitionEligible()
            ->select(['id', 'alias', 'updated_at'])
            ->orderBy('id')
            ->each(fn (Team $team) => $urls->push(
                $this->entry(route('teams.show', $team->routeIdentifier()), $team->updated_at?->toIso8601String()),
            ));

        Tournament::query()
            ->where('status', TournamentStatusEnum::CONFIRMED->value)
            ->select(['id', 'alias', 'updated_at'])
            ->orderBy('id')
            ->each(fn (Tournament $tournament) => $urls->push(
                $this->entry(route('tournaments.show', $tournament->routeIdentifier()), $tournament->updated_at?->toIso8601String()),
            ));

        SportsSection::query()
            ->where('status', SportsSectionStatusEnum::ACTIVE->value)
            ->select(['alias', 'updated_at'])
            ->orderBy('id')
            ->each(fn (SportsSection $section) => $urls->push(
                $this->entry(route('sports-sections.show', $section), $section->updated_at?->toIso8601String()),
            ));

        /** @var Collection<int, array{loc: string, lastmod: string|null}> $urls */
        $urls = $urls->unique('loc')->sortBy('loc')->values();

        return response()
            ->view('seo.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /** @return array{loc: string, lastmod: string|null} */
    private function entry(string $loc, ?string $lastmod = null): array
    {
        return [
            'loc' => $loc,
            'lastmod' => $lastmod,
        ];
    }
}
