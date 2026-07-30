<?php

namespace Tests\Feature\Event;

use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Participation\PlayerObjectiveAssessment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GameAndTeamWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_creates_team_and_receives_owner_membership(): void
    {
        $owner = User::factory()->create(['username' => 'team-owner']);

        $response = $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Северные совы',
            'description' => 'Постоянная команда.',
        ]);

        $team = Team::query()->firstOrFail();
        $response->assertRedirect(route('teams.show', $team->routeIdentifier()));
        $this->assertNull($team->temporary_for_event_id);
        $this->assertDatabaseHas('contract_memberships', [
            'scope_type' => 'team',
            'scope_id' => $team->id,
            'user_id' => $owner->id,
            'access_level' => TeamMembershipAccessLevelEnum::OWNER->value,
        ]);
    }

    public function test_standalone_game_uses_team_snapshots_and_confirmed_statistics_update_objective_assessment(): void
    {
        $ownerA = User::factory()->create(['username' => 'owner-a']);
        $playerA = User::factory()->create(['username' => 'player-a']);
        $ownerB = User::factory()->create(['username' => 'owner-b']);
        $playerB = User::factory()->create(['username' => 'player-b']);
        $teamA = $this->createTeam($ownerA, 'Команда А');
        $teamB = $this->createTeam($ownerB, 'Команда Б');
        $this->addPlayer($ownerA, $teamA, $playerA);
        $this->addPlayer($ownerB, $teamB, $playerB);
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end, EventTypeEnum::GAME),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'side_a_size' => 3,
            'side_b_size' => 4,
        ])->assertRedirect();

        $game = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();
        $this->assertSame(4, $game->gameRosterEntries()->count());
        $this->assertSame('3×4', $game->gameDetail->formatLabel());
        $this->assertCount(2, $game->gameSides);

        $this->actingAs($ownerA)->patch(
            route('events.game.roster', $game->routeIdentifier()),
            [
                'side_a_user_ids' => [$ownerA->id],
                'side_b_user_ids' => [$ownerB->id],
            ],
        )->assertSessionHas('status');
        $this->assertSame(2, $game->gameRosterEntries()->count());

        $game->forceFill([
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ])->save();
        $statistics = [
            'scores' => ['A' => 12, 'B' => 8],
            'players' => [
                $ownerA->id => $this->playerStatistics([
                    'minutes' => 40,
                    'close_made' => 3,
                    'close_attempted' => 5,
                    'assists' => 4,
                    'steals' => 2,
                ]),
                $ownerB->id => $this->playerStatistics([
                    'minutes' => 35,
                    'mid_made' => 2,
                    'mid_attempted' => 5,
                    'defensive_rebounds' => 5,
                ]),
            ],
        ];

        $this->actingAs($ownerA)
            ->patch(route('events.game.statistics', $game->routeIdentifier()), $statistics)
            ->assertSessionHas('status');
        $this->actingAs($ownerA)
            ->post(route('events.game.statistics.confirm', $game->routeIdentifier()))
            ->assertSessionHas('status');

        $this->assertSame(
            GameStatisticsStatusEnum::CONFIRMED,
            $game->gameDetail()->firstOrFail()->statistics_status,
        );
        $assessment = PlayerObjectiveAssessment::query()->where('user_id', $ownerA->id)->firstOrFail();
        $this->assertSame(1, $assessment->games_count);
        $this->assertSame('0.1000', $assessment->confidence);
        $this->assertSame('10.00', $assessment->stamina);
    }

    public function test_training_organizer_creates_non_overlapping_mini_game_from_confirmed_participants(): void
    {
        $organizer = User::factory()->create(['username' => 'training-organizer']);
        $participantA = User::factory()->create(['username' => 'participant-a']);
        $participantB = User::factory()->create(['username' => 'participant-b']);
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($organizer)->post(
            route('events.store'),
            $this->eventPayload($venue, $start, $end, EventTypeEnum::TRAINING),
        )->assertRedirect();
        $training = Event::query()->where('type', EventTypeEnum::TRAINING->value)->firstOrFail();
        $this->actingAs($participantA)->post(route('events.join', $training->routeIdentifier()));
        $this->actingAs($participantB)->post(route('events.join', $training->routeIdentifier()));
        $payload = [
            'title' => 'Мини-игра 1',
            'starts_at' => $start->format('H:i'),
            'ends_at' => $start->addHour()->format('H:i'),
            'side_a_name' => 'Оранжевые',
            'side_b_name' => 'Чёрные',
            'side_a_size' => 1,
            'side_b_size' => 1,
            'side_a_user_ids' => [$participantA->id],
            'side_b_user_ids' => [$participantB->id],
        ];
        $this->actingAs($organizer)
            ->post(route('events.games.store', $training->routeIdentifier()), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $miniGame = Event::query()->where('parent_event_id', $training->id)->firstOrFail();
        $this->assertSame(EventTypeEnum::GAME, $miniGame->type);
        $this->assertNull($miniGame->booking);
        $this->assertSame(2, $miniGame->gameRosterEntries()->count());
        $this->assertSame(2, Team::query()->where('temporary_for_event_id', $miniGame->id)->count());
        $this->assertSame(
            ['Оранжевые', 'Чёрные'],
            $miniGame->gameSides()->orderBy('slot')->pluck('display_name')->all(),
        );

        $this->actingAs($organizer)
            ->from(route('events.show', $training->routeIdentifier()))
            ->post(route('events.games.store', $training->routeIdentifier()), [
                ...$payload,
                'title' => 'Пересекающаяся мини-игра',
            ])
            ->assertSessionHas('error', 'В выбранное время уже запланирована другая мини-игра.');
        $this->assertSame(1, Event::query()->where('parent_event_id', $training->id)->count());
    }

    public function test_mini_game_can_be_created_without_time_and_rejects_format_larger_than_available_roster(): void
    {
        $organizer = User::factory()->create(['username' => 'unscheduled-organizer']);
        $participantA = User::factory()->create(['username' => 'unscheduled-a']);
        $participantB = User::factory()->create(['username' => 'unscheduled-b']);
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($organizer)->post(
            route('events.store'),
            $this->eventPayload($venue, $start, $end, EventTypeEnum::GAME_TRAINING),
        )->assertRedirect();
        $training = Event::query()->where('type', EventTypeEnum::GAME_TRAINING->value)->firstOrFail();
        $this->actingAs($participantA)->post(route('events.join', $training->routeIdentifier()));
        $this->actingAs($participantB)->post(route('events.join', $training->routeIdentifier()));

        $payload = [
            'title' => 'Игра без времени',
            'side_a_name' => 'Светлые',
            'side_b_name' => 'Тёмные',
            'side_a_size' => 1,
            'side_b_size' => 1,
            'side_a_user_ids' => [$participantA->id],
            'side_b_user_ids' => [$participantB->id],
        ];
        $this->actingAs($organizer)
            ->post(route('events.games.store', $training->routeIdentifier()), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $miniGame = Event::query()->where('parent_event_id', $training->id)->firstOrFail();
        $this->assertFalse($miniGame->gameDetail->is_time_scheduled);
        $this->assertTrue($miniGame->starts_at->equalTo($training->starts_at));
        $this->assertTrue($miniGame->ends_at->equalTo($training->ends_at));
        $this->assertSame(
            ['Светлые', 'Тёмные'],
            $miniGame->gameSides()->orderBy('slot')->pluck('display_name')->all(),
        );

        $this->actingAs($organizer)
            ->from(route('events.show', $training->routeIdentifier()))
            ->post(route('events.games.store', $training->routeIdentifier()), [
                ...$payload,
                'title' => 'Формат больше состава',
                'side_a_size' => 2,
                'side_b_size' => 2,
            ])
            ->assertSessionHas(
                'error',
                'Формат мини-игры должен соответствовать доступному составу: от 1×1 до 6×5.',
            );
        $this->assertSame(1, Event::query()->where('parent_event_id', $training->id)->count());
    }

    public function test_game_type_cannot_be_added_through_generic_event_editing(): void
    {
        $organizer = User::factory()->create(['username' => 'type-guard-organizer']);
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($organizer)->post(
            route('events.store'),
            $this->eventPayload($venue, $start, $end, EventTypeEnum::TRAINING),
        )->assertRedirect();
        $training = Event::query()->where('type', EventTypeEnum::TRAINING->value)->firstOrFail();

        $this->actingAs($organizer)
            ->from(route('events.edit', $training->routeIdentifier()))
            ->put(route('events.update', $training->routeIdentifier()), [
                ...$this->eventPayload($venue, $start, $end, EventTypeEnum::GAME),
                'title' => 'Попытка превратить тренировку в игру',
            ])
            ->assertRedirect(route('events.edit', $training->routeIdentifier()))
            ->assertSessionHas(
                'error',
                'Тип «Игра» нельзя назначить или изменить через обычное редактирование мероприятия.',
            );

        $this->assertSame(EventTypeEnum::TRAINING, $training->refresh()->type);
        $this->assertNull($training->gameDetail);
    }

    public function test_game_roster_is_historical_and_rejects_empty_or_oversized_sides(): void
    {
        $ownerA = User::factory()->create(['username' => 'history-owner-a']);
        $playerA = User::factory()->create(['username' => 'history-player-a']);
        $ownerB = User::factory()->create(['username' => 'history-owner-b']);
        $teamA = $this->createTeam($ownerA, 'История А');
        $teamB = $this->createTeam($ownerB, 'История Б');
        $this->addPlayer($ownerA, $teamA, $playerA);
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end, EventTypeEnum::GAME),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'side_a_size' => 1,
            'side_b_size' => 1,
        ])->assertRedirect();
        $game = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();
        $playerMembership = $teamA->memberships()->where('user_id', $playerA->id)->firstOrFail();

        $this->actingAs($ownerA)
            ->delete(route('teams.members.destroy', [$teamA->routeIdentifier(), $playerMembership->id]))
            ->assertSessionHas('status');
        $this->assertDatabaseHas('game_roster_entries', [
            'event_id' => $game->id,
            'user_id' => $playerA->id,
            'source_contract_membership_id' => $playerMembership->id,
        ]);

        $this->actingAs($ownerA)
            ->from(route('events.game.manage', $game->routeIdentifier()))
            ->patch(route('events.game.roster', $game->routeIdentifier()), [
                'side_a_user_ids' => [$ownerA->id, $playerA->id],
                'side_b_user_ids' => [$ownerB->id],
            ])
            ->assertSessionHas('error', 'Количество выбранных игроков превышает формат стороны.');

        $this->actingAs($ownerA)
            ->from(route('events.game.manage', $game->routeIdentifier()))
            ->patch(route('events.game.roster', $game->routeIdentifier()), [
                'side_a_user_ids' => [],
                'side_b_user_ids' => [$ownerB->id],
            ])
            ->assertSessionHasErrors('side_a_user_ids');
    }

    public function test_invalid_or_early_statistics_cannot_be_confirmed(): void
    {
        $ownerA = User::factory()->create(['username' => 'stats-owner-a']);
        $ownerB = User::factory()->create(['username' => 'stats-owner-b']);
        $teamA = $this->createTeam($ownerA, 'Статистика А');
        $teamB = $this->createTeam($ownerB, 'Статистика Б');
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end, EventTypeEnum::GAME),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'side_a_size' => 1,
            'side_b_size' => 1,
        ])->assertRedirect();
        $game = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();

        $invalid = [
            'scores' => ['A' => 2, 'B' => 0],
            'players' => [
                $ownerA->id => $this->playerStatistics([
                    'close_made' => 2,
                    'close_attempted' => 1,
                ]),
                $ownerB->id => $this->playerStatistics(),
            ],
        ];
        $this->actingAs($ownerA)
            ->patch(route('events.game.statistics', $game->routeIdentifier()), $invalid)
            ->assertSessionHas('error', 'Попаданий не может быть больше попыток.');
        $this->assertDatabaseCount('game_player_statistics', 0);

        $valid = [
            'scores' => ['A' => 2, 'B' => 0],
            'players' => [
                $ownerA->id => $this->playerStatistics([
                    'close_made' => 1,
                    'close_attempted' => 1,
                ]),
                $ownerB->id => $this->playerStatistics(),
            ],
        ];
        $this->actingAs($ownerA)
            ->patch(route('events.game.statistics', $game->routeIdentifier()), $valid)
            ->assertSessionHas('status');
        $this->actingAs($ownerA)
            ->post(route('events.game.statistics.confirm', $game->routeIdentifier()))
            ->assertSessionHas('error', 'Подтвердить итоговую статистику можно после окончания игры.');
        $this->assertSame(
            GameStatisticsStatusEnum::READY,
            $game->gameDetail()->firstOrFail()->statistics_status,
        );
    }

    private function createTeam(User $owner, string $name): Team
    {
        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => $name,
            'description' => null,
        ])->assertRedirect();

        return Team::query()->where('name', $name)->firstOrFail();
    }

    private function addPlayer(User $owner, Team $team, User $player): void
    {
        $this->actingAs($owner)->post(route('teams.members.store', $team->routeIdentifier()), [
            'user_id' => $player->id,
            'access_level' => TeamMembershipAccessLevelEnum::PLAYER->value,
        ])->assertSessionHas('status');
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
    private function eventPayload(
        Venue $venue,
        CarbonImmutable $start,
        CarbonImmutable $end,
        EventTypeEnum $type,
    ): array {
        return [
            'venue_id' => $venue->id,
            'title' => $type === EventTypeEnum::GAME ? 'Матч команд' : 'Общая тренировка',
            'type' => $type->value,
            'visibility' => 'public',
            'description' => null,
            'starts_at' => $start->format('Y-m-d\TH:i'),
            'duration_minutes' => (int) $start->diffInMinutes($end),
            'max_participants' => 20,
            'publish_to_telegram' => false,
        ];
    }

    /** @param array<string, int> $overrides
     * @return array<string, int>
     */
    private function playerStatistics(array $overrides = []): array
    {
        return array_merge([
            'minutes' => 0,
            'close_made' => 0,
            'close_attempted' => 0,
            'mid_made' => 0,
            'mid_attempted' => 0,
            'three_made' => 0,
            'three_attempted' => 0,
            'free_throw_made' => 0,
            'free_throw_attempted' => 0,
            'offensive_rebounds' => 0,
            'defensive_rebounds' => 0,
            'assists' => 0,
            'steals' => 0,
            'blocks' => 0,
            'turnovers' => 0,
            'fouls' => 0,
        ], $overrides);
    }
}
