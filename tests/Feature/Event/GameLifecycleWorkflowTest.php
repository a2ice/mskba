<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameActionTypeEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GamePeriodStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\GameRosterEntry;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GameLifecycleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_game_uses_explicit_actual_start_and_end_flow(): void
    {
        $ownerA = User::factory()->create(['username' => 'lifecycle-owner-a']);
        $ownerB = User::factory()->create(['username' => 'lifecycle-owner-b']);
        $teamA = $this->createTeam($ownerA, 'Lifecycle A');
        $teamB = $this->createTeam($ownerB, 'Lifecycle B');
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'side_a_size' => 1,
            'side_b_size' => 1,
        ])->assertRedirect();

        $event = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();
        $game = $event->games()->firstOrFail();
        $this->assertSame($game->id, $event->primary_game_id);
        $this->assertTrue($event->primaryGame->is($game));
        $this->assertNull($game->scheduled_starts_at);
        $this->assertNull($game->scheduled_ends_at);
        $this->assertSame(GameFormatEnum::STREETBALL_1X1, $game->format);
        $this->assertNull($game->periods_count);
        $routeParameters = [$event->routeIdentifier(), $game->id];

        $this->actingAs($ownerA)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee(
                'data-game-live-url="'.route('events.games.live', $routeParameters).'"',
                false,
            )
            ->assertSee('data-game-lifecycle-actions', false)
            ->assertSee('Ожидает запуска')
            ->assertSee('Перейти к управлению игрой')
            ->assertSee(route('events.games.manage', $routeParameters), false);

        $this->actingAs($ownerA)
            ->get(route('events.games.manage', $routeParameters))
            ->assertOk()
            ->assertSee('Ожидает запуска')
            ->assertDontSee('Перейти к управлению игрой');

        $this->actingAs($ownerA)
            ->patchJson(route('events.games.roster', $routeParameters), [
                'side_a_user_ids' => [$ownerA->id],
                'side_b_user_ids' => [$ownerB->id],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Состав игры сохранён.');

        $benchA = User::factory()->create();
        $benchB = User::factory()->create();
        $gameSides = $game->sides()->get()->keyBy('slot');
        foreach ([['A', $benchA], ['B', $benchB]] as [$slot, $bench]) {
            GameRosterEntry::query()->create([
                'game_id' => $game->id,
                'game_side_id' => $gameSides[$slot]->id,
                'user_id' => $bench->id,
                'status' => 'selected',
            ]);
        }

        $this->actingAs($ownerA)
            ->getJson(route('events.games.lifecycle.show', $routeParameters))
            ->assertOk()
            ->assertJsonPath('started', false)
            ->assertJsonPath('ended', false)
            ->assertJsonPath('can_start', true)
            ->assertJsonPath('can_end', false)
            ->assertJsonPath('can_enter_statistics', false)
            ->assertJsonPath('can_manage_score', false);

        $this->actingAs($ownerA)
            ->postJson(route('events.games.start', $routeParameters))
            ->assertOk()
            ->assertJsonPath('message', 'Игра началась.');

        $game->refresh();
        $this->assertNotNull($game->actual_started_at);
        $this->assertNotNull($game->actual_started_by_actor_id);
        $this->assertNull($game->actual_ended_at);
        $this->assertSame(GameStatisticsStatusEnum::ENTERING, $game->statistics_status);
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->fresh()->status);

        $this->actingAs($ownerA)
            ->get(route('events.games.show', $routeParameters))
            ->assertRedirect(route('events.show', $event->routeIdentifier()))
            ->assertStatus(301);

        $this->actingAs($ownerA)
            ->get(route('events.games.manage', $routeParameters))
            ->assertOk()
            ->assertSee('Идёт')
            ->assertSee('data-game-shot-open', false)
            ->assertSee('value="free_throw"', false)
            ->assertSee('data-game-stat-increment="assists"', false)
            ->assertSee('data-game-stat-increment="defensive_rebounds"', false)
            ->assertSee('data-game-score-open', false);

        $this->actingAs($ownerA)
            ->getJson(route('events.games.lifecycle.show', $routeParameters))
            ->assertOk()
            ->assertJsonPath('started', true)
            ->assertJsonPath('ended', false)
            ->assertJsonPath('can_start', false)
            ->assertJsonPath('can_end', true)
            ->assertJsonPath('can_enter_statistics', true)
            ->assertJsonPath('can_manage_score', true);

        $player = $game->rosterEntries()->where('user_id', $ownerA->id)->firstOrFail();
        $this->actingAs($ownerA)
            ->patchJson(route('events.games.statistics', $routeParameters), [
                'scores' => ['A' => 99, 'B' => 0],
                'action' => [
                    'type' => GameActionTypeEnum::SCORE_CORRECTION->value,
                    'user_id' => $ownerA->id,
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Коррекция счёта сохраняется только через управление счётом.');

        $this->assertDatabaseCount('game_actions', 0);
        $this->assertNull($game->sides()->where('slot', 'A')->firstOrFail()->score);

        $this->actingAs($ownerA)
            ->patchJson(route('events.games.statistics', $routeParameters), [
                'scores' => ['A' => 0, 'B' => 0],
                'players' => [
                    $ownerA->id => ['assists' => 1],
                ],
                'action' => [
                    'type' => GameActionTypeEnum::ASSIST->value,
                    'user_id' => $ownerA->id,
                    'payload' => ['field' => 'assists'],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id,
            'sequence' => 1,
            'game_side_id' => $player->game_side_id,
            'user_id' => $ownerA->id,
            'type' => GameActionTypeEnum::ASSIST->value,
        ]);

        $this->actingAs($ownerA)
            ->patchJson(route('events.games.score', $routeParameters), [
                'scores' => ['A' => 2, 'B' => 0],
            ])
            ->assertOk();

        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id,
            'sequence' => 2,
            'game_side_id' => null,
            'type' => GameActionTypeEnum::SCORE_CORRECTION->value,
        ]);
        $this->assertSame($player->game_side_id, $game->fresh()->latestTeamAction->game_side_id);
        $this->get(route('events.games.live', $routeParameters))
            ->assertOk()
            ->assertSee('data-game-live-active-side="A"', false)
            ->assertSee('aria-label="2 : 0"', false);

        $this->actingAs($ownerA)
            ->postJson(route('events.games.start', $routeParameters))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Игра уже началась.');

        $this->actingAs($ownerA)
            ->postJson(route('events.games.end', $routeParameters))
            ->assertOk();

        $game->refresh();
        $this->assertNotNull($game->actual_ended_at);
        $this->assertNotNull($game->actual_ended_by_actor_id);
        $this->assertTrue($game->actual_ended_at->greaterThanOrEqualTo($game->actual_started_at));
        $this->assertSame(GameStatusEnum::AWAITING_RESULT, $game->status);
        $this->assertSame(GameStatisticsStatusEnum::READY, $game->statistics_status);
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->fresh()->status);

        $this->actingAs($ownerA)
            ->getJson(route('events.games.lifecycle.show', $routeParameters))
            ->assertOk()
            ->assertJsonPath('started', true)
            ->assertJsonPath('ended', true)
            ->assertJsonPath('can_end', false)
            ->assertJsonPath('can_enter_statistics', false)
            ->assertJsonPath('can_manage_score', false)
            ->assertJsonPath('can_confirm_result', true);

        $players = $game->rosterEntries()
            ->pluck('user_id')
            ->mapWithKeys(fn ($userId): array => [(int) $userId => ['assists' => 0]])
            ->all();
        $this->actingAs($ownerA)
            ->patchJson(route('events.games.statistics.complete', $routeParameters), [
                'scores' => ['A' => 2, 'B' => 0],
                'players' => $players,
            ])
            ->assertOk()
            ->assertJsonPath('completed', true);

        $this->assertSame(GameStatisticsStatusEnum::CONFIRMED, $game->fresh()->statistics_status);
        $this->assertSame(GameStatusEnum::COMPLETED, $game->fresh()->status);
        $this->assertSame(EventStatusEnum::COMPLETED, $event->fresh()->status);
        $this->assertNotNull($event->fresh()->completed_at);

        $this->actingAs($ownerA)
            ->postJson(route('events.games.end', $routeParameters))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Игра уже закончена.');
    }

    public function test_game_cannot_end_before_it_starts(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $teamA = $this->createTeam($ownerA, 'Early end A');
        $teamB = $this->createTeam($ownerB, 'Early end B');
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'side_a_size' => 1,
            'side_b_size' => 1,
        ])->assertRedirect();

        $event = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();
        $game = $event->games()->firstOrFail();

        $this->actingAs($ownerA)
            ->postJson(route('events.games.end', [$event->routeIdentifier(), $game->id]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Сначала необходимо начать игру.');
    }

    public function test_cancelling_standalone_game_cancels_event_and_releases_booking(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $teamA = $this->createTeam($ownerA, 'Cancel standalone A');
        $teamB = $this->createTeam($ownerB, 'Cancel standalone B');
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'side_a_size' => 1,
            'side_b_size' => 1,
        ])->assertRedirect();

        $event = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();
        $game = $event->primaryGame()->firstOrFail();

        $this->actingAs($ownerA)
            ->patchJson(route('events.games.cancel', [$event->routeIdentifier(), $game->id]))
            ->assertOk()
            ->assertJsonPath('message', 'Игра отменена.');

        $this->assertSame(EventStatusEnum::CANCELLED, $event->fresh()->status);
        $this->assertSame(GameStatusEnum::CANCELLED, $game->fresh()->status);
        $this->assertSame(VenueBookingStatusEnum::CANCELLED, $event->booking->fresh()->status);
    }

    public function test_manual_game_configuration_is_stored_as_custom_format(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $teamA = $this->createTeam($ownerA, 'Custom format A');
        $teamB = $this->createTeam($ownerB, 'Custom format B');
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'game_format' => GameFormatEnum::BASKETBALL_5X5->value,
            'side_a_size' => 4,
            'side_b_size' => 5,
            'scoring_type' => 'basketball',
            'timing_mode' => GameTimingModeEnum::PERIODS->value,
            'periods_count' => 4,
        ])->assertRedirect();

        $game = Event::query()
            ->where('type', EventTypeEnum::GAME->value)
            ->firstOrFail()
            ->primaryGame()
            ->firstOrFail();

        $this->assertSame(GameFormatEnum::CUSTOM, $game->format);
        $this->assertSame(4, $game->side_a_size);
        $this->assertSame(5, $game->side_b_size);
        $this->assertSame(GameTimingModeEnum::PERIODS, $game->timing_mode);
        $this->assertSame(4, $game->periods_count);
        $this->assertCount(4, $game->periods);
    }

    public function test_period_game_requires_active_period_and_closes_periods_in_order(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $teamA = $this->createTeam($ownerA, 'Periods A');
        $teamB = $this->createTeam($ownerB, 'Periods B');
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'game_format' => GameFormatEnum::CUSTOM->value,
            'side_a_size' => 1,
            'side_b_size' => 1,
            'scoring_type' => 'basketball',
            'timing_mode' => GameTimingModeEnum::PERIODS->value,
            'periods_count' => 2,
        ])->assertRedirect();
        $event = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();
        $game = $event->primaryGame()->firstOrFail();
        $route = [$event->routeIdentifier(), $game->id];

        $this->actingAs($ownerA)->postJson(route('events.games.start', $route))->assertOk();
        $this->assertSame(GamePeriodStatusEnum::IN_PROGRESS, $game->periods()->where('number', 1)->firstOrFail()->status);

        $this->actingAs($ownerA)->patchJson(route('events.games.statistics', $route), [
            'scores' => ['A' => 0, 'B' => 0],
            'players' => [$ownerA->id => ['assists' => 1]],
            'action' => ['type' => GameActionTypeEnum::ASSIST->value, 'user_id' => $ownerA->id, 'payload' => ['field' => 'assists']],
        ])->assertOk();
        $this->assertSame(1, $game->actions()->firstOrFail()->gamePeriod->number);

        $this->actingAs($ownerA)->postJson(route('events.games.periods.end', $route))->assertOk();
        $this->actingAs($ownerA)->patchJson(route('events.games.score', $route), ['scores' => ['A' => 1, 'B' => 0]])
            ->assertUnprocessable();
        $this->actingAs($ownerA)->postJson(route('events.games.periods.start-next', $route))->assertOk();
        $this->assertSame(GamePeriodStatusEnum::IN_PROGRESS, $game->periods()->where('number', 2)->firstOrFail()->status);
        $this->actingAs($ownerA)->postJson(route('events.games.end', $route))->assertOk();

        $this->assertSame(GamePeriodStatusEnum::COMPLETED, $game->periods()->where('number', 2)->firstOrFail()->status);
        $this->assertNotNull($game->fresh()->actual_ended_at);
    }

    public function test_period_game_can_be_ended_early_with_required_comment(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $teamA = $this->createTeam($ownerA, 'Early A');
        $teamB = $this->createTeam($ownerB, 'Early B');
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'game_format' => GameFormatEnum::CUSTOM->value,
            'side_a_size' => 1,
            'side_b_size' => 1,
            'scoring_type' => 'basketball',
            'timing_mode' => GameTimingModeEnum::PERIODS->value,
            'periods_count' => 4,
        ])->assertRedirect();
        $event = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();
        $game = $event->primaryGame()->firstOrFail();
        $route = [$event->routeIdentifier(), $game->id];

        $this->actingAs($ownerA)->postJson(route('events.games.start', $route))->assertOk();
        $this->actingAs($ownerA)->getJson(route('events.games.lifecycle.show', $route))
            ->assertOk()
            ->assertJsonPath('can_end', false)
            ->assertJsonPath('can_end_early', true);
        $this->actingAs($ownerA)->postJson(route('events.games.end-early', $route), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('comment');
        $this->actingAs($ownerA)->postJson(route('events.games.end-early', $route), [
            'comment' => 'Игрок получил травму, продолжение невозможно.',
        ])->assertOk();

        $game->refresh();
        $this->assertTrue($game->ended_early);
        $this->assertSame('Игрок получил травму, продолжение невозможно.', $game->status_comment);
        $this->assertSame(GameStatusEnum::AWAITING_RESULT, $game->status);
        $this->assertSame(GameStatisticsStatusEnum::READY, $game->statistics_status);
        $this->assertNotNull($game->actual_ended_at);
        $this->assertSame(GamePeriodStatusEnum::COMPLETED, $game->periods()->where('number', 1)->firstOrFail()->status);
        $this->assertSame(GamePeriodStatusEnum::SCHEDULED, $game->periods()->where('number', 2)->firstOrFail()->status);
    }

    private function createTeam(User $owner, string $name): Team
    {
        $owner->update(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => $name,
            'description' => null,
            'creator_sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
        ])->assertRedirect();

        return Team::query()->where('name', $name)->firstOrFail();
    }

    /** @return array{Venue, CarbonImmutable, CarbonImmutable} */
    private function availableVenue(): array
    {
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(5)->setTime(12, 0);
        $end = $start->addHours(2);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $schedule = VenueSchedule::factory()->for($venue)->create(['timezone' => 'Europe/Moscow']);
        VenueScheduleInterval::factory()->for($schedule, 'schedule')->create([
            'day_of_week' => $start->isoWeekday(),
            'starts_at' => '09:00',
            'ends_at' => '18:00',
            'sort_order' => 0,
        ]);

        return [$venue, $start, $end];
    }

    /** @return array<string, mixed> */
    private function eventPayload(Venue $venue, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'venue_id' => $venue->id,
            'title' => 'Игра с фактическим временем',
            'type' => EventTypeEnum::GAME->value,
            'visibility' => 'public',
            'description' => null,
            'starts_at' => $start->format('Y-m-d\TH:i'),
            'duration_minutes' => (int) $start->diffInMinutes($end),
            'max_participants' => 20,
            'publish_to_telegram' => false,
        ];
    }
}
