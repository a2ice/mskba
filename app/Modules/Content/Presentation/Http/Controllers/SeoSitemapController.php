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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            $this->entry(route('participants.players')),
            $this->entry(route('participants.coaches')),
            $this->entry(route('participants.index')),
        ]);

        $this->appendSource(
            $urls,
            'content',
            ContentItem::query()
                ->publishedInFeed()
                ->select(['id', 'alias', 'updated_at'])
                ->orderBy('id'),
            fn (ContentItem $content): string => route('news.show', $content->alias),
        );

        $this->appendSource(
            $urls,
            'venues',
            Venue::query()
                ->where('status', VenueStatusEnum::CONFIRMED->value)
                ->whereNull('canonical_venue_id')
                ->select(['id', 'alias', 'updated_at'])
                ->orderBy('id'),
            fn (Venue $venue): string => route('venues.show', $venue->routeIdentifier()),
        );

        $this->appendSource(
            $urls,
            'events',
            Event::query()
                ->where('visibility', EventVisibilityEnum::PUBLIC->value)
                ->whereIn('status', [EventStatusEnum::PUBLISHED->value, EventStatusEnum::COMPLETED->value])
                ->select(['id', 'alias', 'updated_at'])
                ->orderBy('id'),
            fn (Event $event): string => route('events.show', $event->routeIdentifier()),
        );

        $this->appendSource(
            $urls,
            'teams',
            Team::query()
                ->competitionEligible()
                ->select(['id', 'alias', 'updated_at'])
                ->orderBy('id'),
            fn (Team $team): string => route('teams.show', $team->routeIdentifier()),
        );

        $this->appendSource(
            $urls,
            'tournaments',
            Tournament::query()
                ->where('status', TournamentStatusEnum::CONFIRMED->value)
                ->select(['id', 'alias', 'updated_at'])
                ->orderBy('id'),
            fn (Tournament $tournament): string => route('tournaments.show', $tournament->routeIdentifier()),
        );

        $this->appendSource(
            $urls,
            'sports_sections',
            SportsSection::query()
                ->where('status', SportsSectionStatusEnum::ACTIVE->value)
                ->select(['id', 'alias', 'updated_at'])
                ->orderBy('id'),
            fn (SportsSection $section): string => route('sports-sections.show', $section->alias),
        );

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .view('seo.sitemap', ['urls' => $urls->unique('loc')->sortBy('loc')->values()])->render();

        return response($xml)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * Dynamic sitemap sources are isolated from one another so one malformed
     * production record cannot turn the whole sitemap into a 500 response.
     *
     * @param Collection<int, array{loc: string, lastmod: string|null}> $urls
     */
    private function appendSource(Collection $urls, string $source, Builder $query, callable $urlResolver): void
    {
        try {
            foreach ($query->cursor() as $model) {
                try {
                    $urls->push($this->entry(
                        $urlResolver($model),
                        $model->updated_at?->toAtomString(),
                    ));
                } catch (Throwable $exception) {
                    Log::warning('Sitemap entry skipped because it could not be rendered.', [
                        'source' => $source,
                        'model_id' => $model->getKey(),
                        'exception' => $exception,
                    ]);
                }
            }
        } catch (Throwable $exception) {
            Log::error('Sitemap source could not be loaded.', [
                'source' => $source,
                'exception' => $exception,
            ]);
        }
    }

    /** @return array{loc: string, lastmod: string|null} */
    private function entry(string $loc, ?string $lastmod = null): array
    {
        return compact('loc', 'lastmod');
    }
}
