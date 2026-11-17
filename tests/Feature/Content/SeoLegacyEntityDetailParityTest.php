<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Enums\SeoEntityTypeEnum;
use App\Modules\Content\Domain\Models\PageSeoSetting;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class SeoLegacyEntityDetailParityTest extends TestCase
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

    public function test_published_training_keeps_its_original_url_body_and_seo(): void
    {
        $event = Event::factory()->create([
            'title' => 'Тренировка для индексации',
            'alias' => 'trenirovka-dlya-indeksatsii',
            'type' => EventTypeEnum::GAME_TRAINING,
            'description' => 'Описание сохранённого мероприятия из базы.',
        ]);
        $this->seo(SeoEntityTypeEnum::EVENT, $event->id, 'Индивидуальный SEO заголовок тренировки');

        $url = route('events.show', $event->routeIdentifier());
        $this->get($url)
            ->assertOk()
            ->assertSee('Тренировка для индексации')
            ->assertSee('Описание сохранённого мероприятия из базы.')
            ->assertSee('<title>Индивидуальный SEO заголовок тренировки</title>', false)
            ->assertSee('<link rel="canonical" href="'.$url.'">', false)
            ->assertDontSee('MSKBA App theme');

        $this->assertSame('mskba_app', app(ThemeResolver::class)->active());
        $this->assertTrue(View::exists('theme::pages.venues.index'));
    }

    public function test_standalone_game_keeps_scoreboard_instead_of_soft_404(): void
    {
        [$event, $game] = $this->game(EventTypeEnum::GAME);
        $url = route('events.show', $event->routeIdentifier());

        $this->get($url)
            ->assertOk()
            ->assertSee('Публичная игра для индексации')
            ->assertSee('Команда A')
            ->assertSee('Команда B')
            ->assertDontSee('MSKBA App theme');

        $this->get('https://dev.mskba.ru'.parse_url($url, PHP_URL_PATH))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_embedded_game_permalink_and_public_join_page_are_not_placeholders(): void
    {
        [$event, $game] = $this->game(EventTypeEnum::GAME_TRAINING);

        $this->get(route('events.games.show', [$event->routeIdentifier(), $game->id]))
            ->assertOk()
            ->assertSee('Игра на площадке для теста')
            ->assertDontSee('MSKBA App theme');

        $this->get(route('events.games.live', [$event->routeIdentifier(), $game->id]))
            ->assertOk()
            ->assertDontSee('MSKBA App theme');

        // The join page is public only for a standalone game with individual draft.
        [$standalone, $joinableGame] = $this->game(EventTypeEnum::GAME, GameRecruitmentModeEnum::INDIVIDUAL_DRAFT);
        $this->get(route('events.games.recruitment.join', [$standalone->routeIdentifier(), $joinableGame->id]))
            ->assertOk()
            ->assertDontSee('MSKBA App theme');
    }

    public function test_existing_tournament_permalink_and_check_in_render_real_content(): void
    {
        $tournament = Tournament::factory()->create([
            'title' => 'Кубок для сохранения URL',
            'alias' => 'kubok-dlya-seo',
            'short_description' => 'Описание турнира из действующей БД.',
        ]);
        $this->seo(SeoEntityTypeEnum::TOURNAMENT, $tournament->id, 'SEO Кубок для сохранения URL');

        $url = route('tournaments.show', $tournament->routeIdentifier());
        $this->get($url)
            ->assertOk()
            ->assertSee('Кубок для сохранения URL')
            ->assertSee('Описание турнира из действующей БД.')
            ->assertSee('<title>SEO Кубок для сохранения URL</title>', false)
            ->assertSee('<link rel="canonical" href="'.$url.'">', false)
            ->assertDontSee('MSKBA App theme');

        $this->get(route('tournaments.on-site.show', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertDontSee('MSKBA App theme');
    }

    public function test_existing_section_alias_preserves_page_and_seo(): void
    {
        $section = SportsSection::factory()->create([
            'status' => SportsSectionStatusEnum::ACTIVE,
            'name' => 'Секция для сохранения URL',
            'alias' => 'sektsiya-sohranenie-url',
            'description' => 'Описание секции из действующей БД.',
        ]);
        $this->seo(SeoEntityTypeEnum::SPORTS_SECTION, $section->id, 'SEO Секция для сохранения URL');

        $url = route('sports-sections.show', $section);
        $this->get($url)
            ->assertOk()
            ->assertSee('Секция для сохранения URL')
            ->assertSee('Описание секции из действующей БД.')
            ->assertSee('<title>SEO Секция для сохранения URL</title>', false)
            ->assertSee('<link rel="canonical" href="'.$url.'">', false)
            ->assertDontSee('MSKBA App theme');
    }

    public function test_missing_entity_urls_never_return_placeholder_with_http_200(): void
    {
        $this->get(route('events.show', '9999999-unpublished'))->assertNotFound();
        $this->get(route('tournaments.show', '9999999-unpublished'))->assertNotFound();
        $this->get(route('sports-sections.show', 'nonexisting-section-9999999'))->assertNotFound();
    }

    private function seo(SeoEntityTypeEnum $type, int $id, string $title): void
    {
        PageSeoSetting::query()->create([
            'entity_type' => $type,
            'entity_id' => $id,
            'meta_title' => $title,
            'meta_description' => 'Сохранённое SEO-описание страницы.',
            'meta_keywords' => 'баскетбол, москва',
        ]);
    }

    /** @return array{Event, Game} */
    private function game(EventTypeEnum $type, GameRecruitmentModeEnum $mode = GameRecruitmentModeEnum::PREFORMED_TEAMS): array
    {
        $event = Event::factory()->create([
            'title' => $type === EventTypeEnum::GAME ? 'Публичная игра для индексации' : 'Игра на площадке для теста',
            'alias' => $type === EventTypeEnum::GAME ? 'igra-seo' : 'trenirovka-s-igroy',
            'type' => $type,
        ]);

        $game = Game::query()->create([
            'event_id' => $event->id,
            'created_by_actor_id' => $event->organizer_actor_id,
            'title' => 'Игра на площадке для теста',
            'recruitment_mode' => $mode,
            'timing_mode' => GameTimingModeEnum::WHOLE_GAME,
            'scoring_type' => GameScoringTypeEnum::BASKETBALL,
        ]);
        if ($type === EventTypeEnum::GAME) {
            $event->forceFill(['primary_game_id' => $game->id])->save();
        }

        return [$event->fresh(), $game->fresh()];
    }
}
