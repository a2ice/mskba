<?php

namespace Tests\Feature\Portal;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Portal\Application\Services\OnlineUserPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SiteSummaryTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertSee('data-today-events-text', false);
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
                'online_users' => 1,
                'total_users' => 2,
            ]);

        $this->postJson(route('site-summary.heartbeat'))
            ->assertOk()
            ->assertJson([
                'online_users' => 1,
                'total_users' => 2,
            ]);

        $this->actingAs($secondUser)
            ->postJson(route('site-summary.heartbeat'))
            ->assertOk()
            ->assertJson([
                'online_users' => 2,
                'total_users' => 2,
            ]);
    }

    public function test_presence_expires_after_the_configured_activity_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00', 'Europe/Moscow'));
        config()->set('site_summary.presence_window_seconds', 120);

        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $presence = app(OnlineUserPresence::class);

        $presence->touch((int) $user->id);
        $this->assertSame(1, $presence->count());

        Carbon::setTestNow(Carbon::parse('2026-07-23 12:02:01', 'Europe/Moscow'));

        $this->assertSame(0, $presence->count());
    }
}
