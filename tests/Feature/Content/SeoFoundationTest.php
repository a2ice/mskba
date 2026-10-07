<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeoFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_exposes_russian_language_and_social_metadata(): void
    {
        $this->get(route('welcome'))
            ->assertOk()
            ->assertSee('<html lang="ru">', false)
            ->assertSee('<title>MSKBA — баскетбол в Москве</title>', false)
            ->assertSee('name="description" content="Игры, тренировки, баскетбольные площадки, команды и турниры Москвы и области в одном месте."', false)
            ->assertSee('property="og:site_name" content="MSKBA"', false)
            ->assertSee('name="twitter:card" content="summary_large_image"', false)
            ->assertSee('"@type":"SportsOrganization"', false)
            ->assertSee('"@type":"WebSite"', false);
    }

    public function test_feed_pagination_has_self_canonical_and_navigation_links(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 13) as $index) {
            ContentItem::query()->create([
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
                'type' => 'material',
                'title' => 'Материал '.$index,
                'alias' => 'material-'.$index,
                'short_description' => 'Краткое описание '.$index,
                'full_description' => 'Полное описание '.$index,
                'publish_in_feed' => true,
                'publish_in_telegram' => false,
                'feed_published_at' => now()->subMinutes($index),
            ]);
        }

        $this->get(route('news.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('news.index', ['page' => 2]).'">', false)
            ->assertSee('<link rel="prev"', false);
    }

    public function test_news_article_exposes_news_article_structured_data(): void
    {
        $content = $this->publishedContent();

        $this->get(route('news.show', $content->alias))
            ->assertOk()
            ->assertSee('"@type":"NewsArticle"', false)
            ->assertSee('"headline":"SEO материал"', false)
            ->assertSee('"mainEntityOfPage"', false);
    }

    public function test_sitemap_lists_public_feed_material_and_excludes_draft(): void
    {
        $published = $this->publishedContent();
        $draft = $this->publishedContent([
            'title' => 'Черновик',
            'alias' => 'draft-material',
            'status' => 'draft',
        ]);

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<?xml version="1.0" encoding="UTF-8"?>', false)
            ->assertSee(route('welcome'), false)
            ->assertSee(route('news.show', $published->alias), false)
            ->assertDontSee(route('news.show', $draft->alias), false);
    }

    public function test_sitemap_renders_every_public_entity_source(): void
    {
        $actor = Actor::factory()->create();

        $venue = Venue::factory()->create([
            'alias' => 'seo-venue',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);

        $event = Event::factory()->create([
            'organizer_actor_id' => $actor->id,
            'venue_id' => $venue->id,
            'alias' => 'seo-event',
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
        ]);

        $team = Team::query()->create([
            'created_by_actor_id' => $actor->id,
            'name' => 'SEO Team',
            'base_name' => 'SEO Team',
            'normalized_name' => 'seo-team',
            'name_sequence' => 1,
            'alias' => 'seo-team',
            'status' => TeamStatusEnum::ACTIVE,
        ]);

        $tournament = Tournament::factory()->create([
            'created_by_actor_id' => $actor->id,
            'title' => 'SEO Tournament',
            'alias' => 'seo-tournament',
            'status' => TournamentStatusEnum::CONFIRMED,
        ]);

        $section = SportsSection::factory()->create([
            'created_by_actor_id' => $actor->id,
            'name' => 'SEO Section',
            'alias' => 'seo-section',
            'status' => SportsSectionStatusEnum::ACTIVE,
        ]);

        $canonicalDuplicate = Venue::factory()->create([
            'alias' => 'seo-venue-duplicate',
            'status' => VenueStatusEnum::CONFIRMED,
            'canonical_venue_id' => $venue->id,
        ]);

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee(route('venues.show', $venue->routeIdentifier()), false)
            ->assertSee(route('events.show', $event->routeIdentifier()), false)
            ->assertSee(route('teams.show', $team->routeIdentifier()), false)
            ->assertSee(route('tournaments.show', $tournament->routeIdentifier()), false)
            ->assertSee(route('sports-sections.show', $section->alias), false)
            ->assertDontSee(route('venues.show', $canonicalDuplicate->routeIdentifier()), false);
    }

    /** @param array<string, mixed> $attributes */
    private function publishedContent(array $attributes = []): ContentItem
    {
        $user = User::factory()->create();

        return ContentItem::query()->create([
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'type' => 'material',
            'title' => 'SEO материал',
            'alias' => 'seo-material-'.uniqid(),
            'short_description' => 'Краткое описание SEO материала.',
            'full_description' => 'Полное описание SEO материала.',
            'publish_in_feed' => true,
            'publish_in_telegram' => false,
            'feed_published_at' => now(),
            ...$attributes,
        ]);
    }
}
