<?php

namespace Tests\Feature\Event;

use App\Modules\Analytics\Application\Services\GameLiveViewHistoryRecorder;
use App\Modules\Analytics\Domain\Models\GameLiveViewSession;
use App\Modules\Event\Application\Services\GameLiveAudiencePresence;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class GameLiveAudienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('game_live.presence_store', 'array');
        config()->set('game_live.presence_window_seconds', 60);
        config()->set('game_live.history_session_gap_seconds', 90);
        Cache::store('array')->clear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_live_audience_counts_unique_browsers_and_authenticated_viewers(): void
    {
        $presence = app(GameLiveAudiencePresence::class);

        $this->assertSame(
            ['authenticated' => 0, 'total' => 1],
            $presence->touch(10, 'guest-browser', false)->toArray(),
        );
        $this->assertSame(
            ['authenticated' => 0, 'total' => 1],
            $presence->touch(10, 'guest-browser', false)->toArray(),
        );
        $this->assertSame(
            ['authenticated' => 1, 'total' => 2],
            $presence->touch(10, 'authenticated-browser', true)->toArray(),
        );

        $event = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $game = $this->game($event);

        $this->actingAs(User::factory()->create())
            ->postJson(route('events.games.live.audience', [$event->routeIdentifier(), $game->id]))
            ->assertOk()
            ->assertExactJson(['authenticated' => 1, 'total' => 1]);
    }

    public function test_live_audience_expires_inactive_viewers(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $event = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $game = $this->game($event);
        $presence = app(GameLiveAudiencePresence::class);
        $this->assertSame(['authenticated' => 0, 'total' => 1], $presence->touch($game->id, 'first', false)->toArray());

        Carbon::setTestNow('2026-08-12 12:01:01');

        $this->assertSame(['authenticated' => 0, 'total' => 1], $presence->touch($game->id, 'second', false)->toArray());
    }

    public function test_live_audience_rejects_game_from_another_event(): void
    {
        $event = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $otherEvent = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $game = $this->game($otherEvent);

        $this->postJson(route('events.games.live.audience', [$event->routeIdentifier(), $game->id]))
            ->assertNotFound();
    }

    public function test_terminal_game_rejects_new_live_presence_and_history(): void
    {
        $event = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $game = $this->game($event);
        $game->update(['status' => GameStatusEnum::COMPLETED]);

        $this->postJson(route('events.games.live.audience', [$event->routeIdentifier(), $game->id]))
            ->assertOk()
            ->assertExactJson([
                'authenticated' => 0,
                'total' => 0,
                'terminal' => true,
            ]);

        $this->assertDatabaseCount('game_live_view_sessions', 0);

        $game->update([
            'status' => GameStatusEnum::AWAITING_RESULT,
            'actual_ended_at' => now(),
        ]);

        $this->postJson(route('events.games.live.audience', [$event->routeIdentifier(), $game->id]))
            ->assertExactJson([
                'authenticated' => 0,
                'total' => 0,
                'terminal' => true,
            ]);
    }

    public function test_live_page_exposes_audience_heartbeat_contract(): void
    {
        $event = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $game = $this->game($event);

        $this->get(route('events.games.live', [$event->routeIdentifier(), $game->id]))
            ->assertOk()
            ->assertSee('data-game-live-audience', false)
            ->assertSee('Авторизованные зрители / все зрители')
            ->assertSee(route('events.games.live.audience', [$event->routeIdentifier(), $game->id]));

        $game->update(['status' => GameStatusEnum::COMPLETED]);

        $this->get(route('events.games.live', [$event->routeIdentifier(), $game->id]))
            ->assertOk()
            ->assertSee('data-game-live-terminal="1"', false);
    }

    public function test_audience_heartbeat_records_view_history_and_starts_a_new_session_after_inactivity(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $event = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $game = $this->game($event);
        $user = User::factory()->create();
        $history = app(GameLiveViewHistoryRecorder::class);

        $history->record($game->id, 'same-browser', $user->id, null);

        Carbon::setTestNow('2026-08-12 12:00:45');
        $history->record($game->id, 'same-browser', $user->id, null);

        $session = GameLiveViewSession::query()->sole();
        $this->assertSame($game->id, $session->game_id);
        $this->assertSame($user->id, $session->user_id);
        $this->assertSame(45, $session->watched_seconds);

        Carbon::setTestNow('2026-08-12 12:02:16');
        $history->record($game->id, 'same-browser', $user->id, null);

        $this->assertSame(2, GameLiveViewSession::query()->count());
    }

    public function test_only_authorized_game_managers_see_the_audience_report(): void
    {
        $organizer = User::factory()->create(['username' => 'audience-organizer']);
        $responsible = User::factory()->create(['username' => 'audience-responsible']);
        $otherManager = User::factory()->create(['username' => 'other-manager']);
        $actor = app(CurrentActorResolver::class)->resolve($organizer, null);
        $event = Event::factory()->create([
            'type' => EventTypeEnum::GAME_TRAINING,
            'organizer_actor_id' => $actor->id,
        ]);
        $game = $this->game($event);
        $route = route('events.games.manage', [$event->routeIdentifier(), $game->id]);
        $history = app(GameLiveViewHistoryRecorder::class);
        $history->record($game->id, 'viewer', $responsible->id, null);

        $this->actingAs($organizer)
            ->get($route)
            ->assertOk()
            ->assertSee('Аудитория трансляции')
            ->assertSee('audience-responsible');

        foreach ([
            [$responsible, EventResponsibilityPermissionEnum::VIEW_MINI_GAME_AUDIENCE],
            [$otherManager, EventResponsibilityPermissionEnum::UPDATE_MINI_GAME],
        ] as [$user, $permission]) {
            $participant = $event->participants()->create([
                'user_id' => $user->id,
                'role' => EventParticipantRoleEnum::PARTICIPANT,
                'status' => EventParticipantStatusEnum::CONFIRMED,
                'responsibility_status' => EventResponsibilityStatusEnum::ACCEPTED,
            ]);
            $participant->responsibilityPermissions()->create(['permission' => $permission]);
        }

        $this->actingAs($responsible)
            ->get($route)
            ->assertOk()
            ->assertSee('Аудитория трансляции');

        $this->actingAs($otherManager)
            ->get($route)
            ->assertOk()
            ->assertDontSee('Аудитория трансляции');
    }

    private function game(Event $event): Game
    {
        return Game::query()->create([
            'event_id' => $event->id,
            'created_by_actor_id' => $event->organizer_actor_id,
            'title' => 'Live audience',
            'status' => GameStatusEnum::SCHEDULED,
            'side_a_size' => 3,
            'side_b_size' => 3,
        ]);
    }
}
