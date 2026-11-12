<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class UnifiedAppCategoryCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'themes.active' => 'mskba_app',
            'features.sports_sections.enabled' => true,
            'seo.indexable_hosts' => ['mskba.ru', 'www.mskba.ru'],
        ]);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->withoutVite();
    }

    public function test_all_six_catalogs_use_one_app_theme_category_layout(): void
    {
        foreach ([
            ['url' => route('venues'), 'heading' => 'Площадки'],
            ['url' => route('shop.index'), 'heading' => 'Магазин'],
            ['url' => route('news.index'), 'heading' => 'Новости'],
            ['url' => route('events.index'), 'heading' => 'Мероприятия'],
            ['url' => route('tournaments.index'), 'heading' => 'Турниры'],
            ['url' => route('sports-sections.index'), 'heading' => 'Секции'],
        ] as $catalog) {
            $this->get($catalog['url'])
                ->assertOk()
                ->assertSee('data-default-category', false)
                ->assertSee('data-default-category-view="cards"', false)
                ->assertSee('data-default-category-results="cards"', false)
                ->assertSee('app-catalog-tools__views', false)
                ->assertSee($catalog['heading'])
                ->assertSee('id="mskba-app"', false)
                ->assertDontSee('MSKBA App theme');
        }

        $this->assertSame('mskba_app', app(ThemeResolver::class)->active());
        $this->assertTrue(View::exists('theme::layouts.category'));
    }

    public function test_venue_infinite_scroll_and_shop_card_hooks_survive_shared_layout(): void
    {
        $this->get(route('venues'))
            ->assertOk()
            ->assertSee('data-venue-infinite-catalog', false)
            ->assertSee('data-venue-items', false)
            ->assertSee('data-venue-sentinel', false)
            ->assertSee('id="venue-catalog-title"', false);

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee('app-shop__products', false)
            ->assertSee('shop-filters-desktop', false)
            ->assertSee('shop-filters-mobile', false)
            ->assertSee('id="app-shop-title"', false);
    }

    public function test_filter_controls_match_guideline_and_have_mobile_equivalents(): void
    {
        foreach ([
            ['url' => route('news.index'), 'prefix' => 'news'],
            ['url' => route('events.index'), 'prefix' => 'events'],
            ['url' => route('tournaments.index'), 'prefix' => 'tournaments'],
            ['url' => route('sports-sections.index'), 'prefix' => 'sections'],
        ] as $catalog) {
            $this->get($catalog['url'])
                ->assertOk()
                ->assertSee('select-control', false)
                ->assertSee($catalog['prefix'].'-category-filter-desktop', false)
                ->assertSee($catalog['prefix'].'-category-filter-mobile', false)
                ->assertSee('app-category__mobile-filters', false)
                ->assertSee('app-category__layout', false);
        }
    }

    public function test_cards_and_list_use_same_layout_and_preserve_query_on_switch(): void
    {
        foreach ([
            route('news.index', ['type' => 'material', 'view' => 'list']),
            route('events.index', ['type' => 'games', 'view' => 'list']),
            route('tournaments.index', ['period' => 'upcoming', 'view' => 'list']),
            route('sports-sections.index', ['recruiting' => 1, 'view' => 'list']),
        ] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('data-default-category-view="list"', false)
                ->assertSee('data-default-category-results="list"', false)
                ->assertSee('app-category__items--list', false)
                ->assertSee('name="view" value="list"', false);
        }
    }

    public function test_news_search_and_type_filter_real_records_only(): void
    {
        $owner = User::factory()->create();
        foreach ([
            ['title' => 'Уникальный мяч в новости', 'type' => 'material', 'alias' => 'unique-ball-news'],
            ['title' => 'Другая публикация', 'type' => 'user', 'alias' => 'another-news'],
        ] as $item) {
            ContentItem::query()->create([
                'created_by_user_id' => $owner->id,
                'updated_by_user_id' => $owner->id,
                'status' => 'published',
                'short_description' => 'Описание из базы.',
                'full_description' => 'Содержимое из базы.',
                'publish_in_feed' => true,
                'publish_in_telegram' => false,
                'feed_published_at' => now()->subDay(),
                ...$item,
            ]);
        }

        $this->get(route('news.index', ['q' => 'мяч', 'type' => 'material']))
            ->assertOk()
            ->assertSee('Уникальный мяч в новости')
            ->assertDontSee('Другая публикация')
            ->assertSee(route('news.show', 'unique-ball-news'), false);

        $this->get(route('news.index', ['q' => 'отсутствует']))
            ->assertOk()
            ->assertSee('Публикации не найдены');
    }

    public function test_event_type_filter_and_tournament_section_data_use_live_models(): void
    {
        Event::factory()->create([
            'type' => EventTypeEnum::GAME,
            'title' => 'Открытая игра категории',
            'ends_at' => now()->addDays(3),
        ]);
        Event::factory()->create([
            'type' => EventTypeEnum::GAME_TRAINING,
            'title' => 'Игровая тренировка категории',
            'ends_at' => now()->addDays(3),
        ]);

        $this->get(route('events.index', ['type' => 'games']))
            ->assertOk()
            ->assertSee('Открытая игра категории')
            ->assertSee('Игровая тренировка категории');

        $this->get(route('events.index', ['type' => EventTypeEnum::GAME->value]))
            ->assertOk()
            ->assertSee('Открытая игра категории')
            ->assertDontSee('Игровая тренировка категории');

        $tournament = Tournament::factory()->create(['title' => 'Турнир единого каталога']);
        $this->get(route('tournaments.index'))
            ->assertOk()
            ->assertSee('Турнир единого каталога')
            ->assertSee(route('tournaments.show', $tournament->routeIdentifier()), false);

        $section = SportsSection::factory()->create([
            'name' => 'Секция единого каталога',
            'status' => SportsSectionStatusEnum::ACTIVE,
        ]);
        $this->get(route('sports-sections.index'))
            ->assertOk()
            ->assertSee('Секция единого каталога')
            ->assertSee(route('sports-sections.show', $section), false);
    }

    public function test_all_category_urls_remain_noindex_on_dev(): void
    {
        foreach (['/feed', '/events', '/tournaments', '/sections', '/shop', '/venues'] as $path) {
            $this->get('https://dev.mskba.ru'.$path)
                ->assertOk()
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }
    }
}
