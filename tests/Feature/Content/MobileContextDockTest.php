<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MobileContextDockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'themes.active' => 'mskba_app',
            'features.sports_sections.enabled' => true,
        ]);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->withoutVite();
    }

    public function test_mobile_dock_has_five_actions_and_keeps_legacy_game_training_out_of_play_menu(): void
    {
        $page = $this->get(route('venues'))->assertOk();
        $page->assertSee('data-app-mobile-primary-play-toggle', false)
            ->assertSee('data-app-mobile-primary-filter-toggle', false)
            ->assertSee('data-app-mobile-primary-toggle', false)
            ->assertSee('data-context-filter-host', false)
            ->assertSee('data-context-global-search', false)
            ->assertSee('app-mobile-primary__filter-footer', false)
            ->assertSee('href="'.route('events.index', ['type' => 'game']).'"', false)
            ->assertSee('href="'.route('leagues.index').'"', false);
        $html = $page->getContent();
        $play = explode('id="app-mobile-primary-play"', $html, 2)[1] ?? '';
        $play = explode('</section>', $play, 2)[0];
        $this->assertStringNotContainsString('game_training', $play);
        $this->assertStringNotContainsString('Тренировки', $play);
    }

    public function test_participants_have_native_category_mobile_filters(): void
    {
        $this->get(route('participants.index'))->assertOk()
            ->assertSee('data-default-category', false)
            ->assertSee('participants-category-filter-mobile', false)
            ->assertSee('data-app-mobile-primary-filter-toggle', false);
    }

    public function test_global_search_has_category_selection_and_result_page(): void
    {
        $this->get(route('site.search'))->assertOk()
            ->assertSee('Поиск по MSKBA')
            ->assertSee('name="types[]"', false)
            ->assertSee('noindex,follow', false);

        $this->get(route('site.search', ['q' => 'название-которого-не-существует']))->assertOk()
            ->assertSee('найдено: 0');
    }

    public function test_global_search_omits_system_updates_but_indexes_published_news_and_faq(): void
    {
        $user = User::factory()->create();
        foreach ([
            ['title' => 'Специальная новость контекстдок', 'alias' => 'site-search-published', 'type' => 'material', 'publish_in_feed' => true],
            ['title' => 'Системная новость контекстдок', 'alias' => 'site-search-system', 'type' => 'system_update', 'publish_in_feed' => true],
            ['title' => 'FAQ контекстдок', 'alias' => 'site-search-faq', 'type' => 'faq', 'publish_in_feed' => false],
        ] as $attributes) {
            ContentItem::query()->create([
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
                'status' => 'published',
                'short_description' => 'Описание для поиска.',
                'full_description' => 'Статья для тестирования.',
                'publish_in_telegram' => false,
                'feed_published_at' => now()->subDay(),
                ...$attributes,
            ]);
        }

        $this->get(route('site.search', ['q' => 'контекстдок', 'types' => ['news', 'faq']]))
            ->assertOk()
            ->assertSee('Специальная новость контекстдок')
            ->assertSee('FAQ контекстдок')
            ->assertDontSee('Системная новость контекстдок');
    }
    public function test_fixed_mobile_sheet_has_search_before_expansion_and_result_counter_above_actions(): void
    {
        $response = $this->get(route('venues'))->assertOk()
            ->assertSee('data-context-filter-expand', false)
            ->assertSee('aria-label="Развернуть фильтры"', false)
            ->assertSee('Кол-во результатов:')
            ->assertSee('data-context-filter-result-value', false)
            ->assertSee('data-context-result-total="', false);

        $html = $response->getContent();
        $search = strpos($html, 'data-context-filter-quick-search');
        $expand = strpos($html, 'data-context-filter-expand');
        $scroll = strpos($html, 'data-context-filter-scroll');
        $results = strpos($html, 'data-context-filter-result-value');
        $actions = strpos($html, 'data-context-filter-apply');

        $this->assertNotFalse($search);
        $this->assertNotFalse($expand);
        $this->assertNotFalse($scroll);
        $this->assertNotFalse($results);
        $this->assertNotFalse($actions);
        $this->assertTrue($search < $scroll && $scroll < $results);
        $this->assertTrue($results < $expand && $expand < $actions);
    }

    public function test_all_catalogs_expose_total_results_for_the_mobile_filter_footer(): void
    {
        foreach ([
            route('venues'),
            route('shop.index'),
            route('news.index'),
            route('events.index'),
            route('tournaments.index'),
            route('sports-sections.index'),
            route('leagues.index'),
            route('participants.index'),
            route('teams.index'),
            route('site.search'),
        ] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('data-context-result-total="', false);
        }
    }


}
