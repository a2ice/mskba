<?php

namespace Tests\Feature\Portal;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Portal\Application\Services\OnlineUserPresence;
use App\Modules\Portal\Application\Services\SiteSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SiteSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('site_summary.presence_store', 'array');
        config()->set('site_summary.cache_store', 'array');
        Cache::store('array')->clear();
        app(SiteSummaryService::class)->forgetTodayEvents();
        app(SiteSummaryService::class)->forgetTotalUsers();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_summary_counts_only_games_and_game_trainings_scheduled_for_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00', 'Europe/Moscow'));

        Event::factory()->create([
            'type' => EventTypeEnum::GAME,
            'status' => EventStatusEnum::PUBLISHED,
            'starts_at' => Carbon::parse('2026-07-23 18:00:00', 'Europe/Moscow')->utc(),
        ]);
        Event::factory()->create([
            'type' => EventTypeEnum::GAME_TRAINING,
            'status' => EventStatusEnum::COMPLETED,
            'starts_at' => Carbon::parse('2026-07-23 20:00:00', 'Europe/Moscow')->utc(),
        ]);
        Event::factory()->create([
            'type' => EventTypeEnum::TRAINING,
            'status' => EventStatusEnum::PUBLISHED,
            'starts_at' => Carbon::parse('2026-07-23 19:00:00', 'Europe/Moscow')->utc(),
        ]);
        Event::factory()->create([
            'type' => EventTypeEnum::GAME,
            'status' => EventStatusEnum::CANCELLED,
            'starts_at' => Carbon::parse('2026-07-23 21:00:00', 'Europe/Moscow')->utc(),
        ]);
        Event::factory()->create([
            'type' => EventTypeEnum::GAME,
            'status' => EventStatusEnum::PUBLISHED,
            'starts_at' => Carbon::parse('2026-07-24 18:00:00', 'Europe/Moscow')->utc(),
        ]);

        $this->get(route('welcome'))
            ->assertOk()
            ->assertSee('2 игры сегодня')
            ->assertSee('data-today-events-link', false)
            ->assertSee(route('events.index', [
                'type' => 'games',
                'date_from' => '2026-07-23',
                'date_to' => '2026-07-23',
            ]));
    }

    public function test_games_filter_combines_games_and_game_trainings_for_selected_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00', 'Europe/Moscow'));

        Event::factory()->create([
            'title' => 'Обычная игра',
            'type' => EventTypeEnum::GAME,
            'status' => EventStatusEnum::PUBLISHED,
            'starts_at' => Carbon::parse('2026-07-23 18:00:00', 'Europe/Moscow')->utc(),
        ]);
        Event::factory()->create([
            'title' => 'Игровая тренировка',
            'type' => EventTypeEnum::GAME_TRAINING,
            'status' => EventStatusEnum::PUBLISHED,
            'starts_at' => Carbon::parse('2026-07-23 20:00:00', 'Europe/Moscow')->utc(),
        ]);
        Event::factory()->create([
            'title' => 'Обычная тренировка',
            'type' => EventTypeEnum::TRAINING,
            'status' => EventStatusEnum::PUBLISHED,
            'starts_at' => Carbon::parse('2026-07-23 19:00:00', 'Europe/Moscow')->utc(),
        ]);

        $this->get(route('events.index', [
            'type' => 'games',
            'date_from' => '2026-07-23',
            'date_to' => '2026-07-23',
        ]))
            ->assertOk()
            ->assertSee('Обычная игра')
            ->assertSee('Игровая тренировка')
            ->assertDontSee('Обычная тренировка')
            ->assertSee('Игры и игровые тренировки');
    }

    public function test_zero_summary_hides_authenticated_online_badge_and_uses_empty_games_copy(): void
    {
        $response = $this->get(route('welcome'));

        $response
            ->assertOk()
            ->assertSee('Новая игра')
            ->assertSee('data-online-visitors-count', false)
            ->assertSee('data-mobile-online-summary', false);

        $this->assertMatchesRegularExpression(
            '/data-online-summary\s+hidden/u',
            $response->getContent()
        );
    }

    public function test_heartbeat_counts_unique_active_users_and_excludes_blocked_users_from_total(): void
    {
        $firstUser = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $secondUser = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        User::factory()->create(['status' => UserStatusEnum::BLOCKED]);

        $this->actingAs($firstUser)
            ->postJson(route('site-summary.heartbeat'))
            ->assertOk()
            ->assertJson([
                'today_events' => 0,
                'today_events_text' => 'Новая игра',
                'online_users' => 1,
                'total_users' => 2,
            ])
            ->assertJsonStructure(['online_visitors']);

        $this->postJson(route('site-summary.heartbeat'))
            ->assertOk()
            ->assertJson([
                'online_users' => 1,
                'total_users' => 2,
            ])
            ->assertJsonStructure(['online_visitors']);

        $this->actingAs($secondUser)
            ->postJson(route('site-summary.heartbeat'))
            ->assertOk()
            ->assertJson([
                'online_users' => 2,
                'total_users' => 2,
            ])
            ->assertJsonStructure(['online_visitors']);
    }

    public function test_user_and_visitor_presence_expire_after_the_configured_activity_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00', 'Europe/Moscow'));
        config()->set('site_summary.presence_window_seconds', 120);

        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $presence = app(OnlineUserPresence::class);

        $presence->touch((int) $user->id);
        $presence->touchVisitor(101);
        $presence->touchVisitor(101);
        $presence->touchVisitor(202);

        $this->assertSame(1, $presence->count());
        $this->assertSame(2, $presence->visitorCount());

        Carbon::setTestNow(Carbon::parse('2026-07-23 12:02:01', 'Europe/Moscow'));

        $this->assertSame(0, $presence->count());
        $this->assertSame(0, $presence->visitorCount());
    }
}
