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

final class SeoSitemapController extends Controller
{
    public function __invoke(): Response
    {
        $urls = collect([
            $this->entry(route('welcome')),
            $this->entry(route('news.index')),
            $this->entry(route('venues')),
            $this->entry(route('events.index')),
            $this->entry(route('teams.index')),
            $this->entry(route('tournaments.index')),
            $this->entry(route('sports-sections.index')),
            $this->entry(route('players')),
            $this->entry(route('coaches')),
            $this->entry(route('participants')),
        ]);

        $this->append(
            $urls,
            ContentItem::query()->publishedInFeed()->orderBy('id')->get(['id', 'alias', 'updated_at']),
            fn (ContentItem $content): string => route('news.show', $content->alias),
        );

        $this->append(
            $urls,
            Venue::query()->where('status', VenueStatusEnum::CONFIRMED->value)->orderBy('id')->get(['id', 'alias', 'updated_at']),
            fn (Venue $venue): string => route('venues.show', $venue->routeIdentifier()),
        );

        $this->append(
            $urls,
            Event::query()
                ->where('visibility', EventVisibilityEnum::PUBLIC->value)
                ->whereIn('status', [EventStatusEnum::PUBLISHED->value, EventStatusEnum::COMPLETED->value])
                ->orderBy('id')
                ->get(['id', 'alias', 'updated_at']),
            fn (Event $event): string => route('events.show', $event->routeIdentifier()),
        );

        $this->append(
            $urls,
            Team::query()->competitionEligible()->orderBy('id')->get(['id', 'alias', 'updated_at']),
            fn (Team $team): string => route('teams.show', $team->routeIdentifier()),
        );

        $this->append(
            $urls,
            Tournament::query()->where('status', TournamentStatusEnum::CONFIRMED->value)->orderBy('id')->get(['id', 'alias', 'updated_at']),
            fn (Tournament $tournament): string => route('tournaments.show', $tournament->routeIdentifier()),
        );

        $this->append(
            $urls,
            SportsSection::query()->where('status', SportsSectionStatusEnum::ACTIVE->value)->orderBy('id')->get(['id', 'alias', 'updated_at']),
            fn (SportsSection $section): string => route('sports-sections.show', $section->alias),
        );

        return response()
            ->view('seo.sitemap', ['urls' => $urls->unique('loc')->values()])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * @param Collection<int, array{loc: string, lastmod: string|null}> $urls
     * @param Collection<int, object> $models
     */
    private function append(Collection $urls, Collection $models, callable $urlResolver): void
    {
        foreach ($models as $model) {
            $urls->push($this->entry(
                $urlResolver($model),
                $model->updated_at?->toAtomString(),
            ));
        }
    }

    /** @return array{loc: string, lastmod: string|null} */
    private function entry(string $loc, ?string $lastmod = null): array
    {
        return compact('loc', 'lastmod');
    }
}
