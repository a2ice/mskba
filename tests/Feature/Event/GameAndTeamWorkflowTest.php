<?php

namespace Tests\Feature\Event;

use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
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
            ->patchJson(route('events.game.statistics', $game->routeIdentifier()), $statistics)
            ->assertOk()
            ->assertJsonPath('scores.A', 12)
            ->assertJsonPath('scores.B', 8)
            ->assertJsonPath('calculated_scores.A', 6)
            ->assertJsonPath('calculated_scores.B', 4)
            ->assertJsonPath('player_points.'.$ownerA->id, 6)
            ->assertJsonPath('player_points.'.$ownerB->id, 4);
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

        $this->app['auth']->logout();
        $this->get(route('events.show', $game->routeIdentifier()))
            ->assertOk()
            ->assertSee('Команда А')
            ->assertSee('Команда Б')
            ->assertSee('owner-a')
            ->assertSee('owner-b')
            ->assertSee('Статистика игроков')
            ->assertDontSee('Редактировать игру и статистику');
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
            'has_scheduled_time' => true,
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

        $this->app['auth']->logout();
        $this->get(route('events.show', $training->routeIdentifier()))
            ->assertOk()
            ->assertSee('Мини-игра 1')
            ->assertSee('Оранжевые')
            ->assertSee('Чёрные')
            ->assertSee('—:—')
            ->assertDontSee('Управлять');

        $this->get(route('events.show', $miniGame->routeIdentifier()))
            ->assertOk()
            ->assertSee('Мини-игра 1')
            ->assertSee('Мероприятие:')
            ->assertSee($training->title)
            ->assertSee('target="_blank"', false)
            ->assertSee('data-event-map-open', false)
            ->assertSee('Оранжевые')
            ->assertSee('Чёрные')
            ->assertSee('participant-a')
            ->assertSee('participant-b')
            ->assertSee('Статистика игроков')
            ->assertDontSee('Редактировать игру и статистику');

        $this->actingAs($organizer)
            ->get(route('events.show', $training->routeIdentifier()))
            ->assertOk()
            ->assertSee('Управлять');
        $this->actingAs($organizer)
            ->get(route('events.show', $miniGame->routeIdentifier()))
            ->assertOk()
            ->assertSee('Редактировать игру и статистику');

        $this->actingAs($organizer)
            ->from(route('events.show', $training->routeIdentifier()))
            ->post(route('events.games.store', $training->routeIdentifier()), [
                ...$payload,
                'title' => 'Пересекающаяся мини-игра',
            ])
            ->assertSessionHas('error', 'В выбранное время уже запланирована другая мини-игра.');
        $this->assertSame(1, Event::query()->where('parent_event_id', $training->id)->count());
    }

    public function test_mini_game_inherits_private_visibility_from_parent_event(): void
    {
        $organizer = User::factory()->create(['username' => 'private-game-organizer']);
        $participant = User::factory()->create(['username' => 'private-game-player']);
        $stranger = User::factory()->create(['username' => 'private-game-stranger']);
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($organizer)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end, EventTypeEnum::GAME_TRAINING),
            'visibility' => 'private',
        ])->assertRedirect();
        $training = Event::query()->where('type', EventTypeEnum::GAME_TRAINING->value)->firstOrFail();
        $training->participants()->create([
            'user_id' => $participant->id,
            'role' => EventParticipantRoleEnum::PARTICIPANT,
            'status' => EventParticipantStatusEnum::CONFIRMED,
            'joined_at' => now(),
            'confirmation_version' => $training->participation_confirmation_version,
        ]);

        $this->actingAs($organizer)
            ->post(route('events.games.store', $training->routeIdentifier()), [
                'title' => 'Закрытая мини-игра',
                'side_a_name' => 'Команда A',
                'side_b_name' => 'Команда B',
                'side_a_size' => 1,
                'side_b_size' => 1,
                'side_a_user_ids' => [$organizer->id],
                'side_b_user_ids' => [$participant->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $miniGame = Event::query()->where('parent_event_id', $training->id)->firstOrFail();

        $this->actingAs($stranger)
            ->get(route('events.show', $miniGame->routeIdentifier()))
            ->assertNotFound();
        $this->actingAs($organizer)
            ->get(route('events.show', $miniGame->routeIdentifier()))
            ->assertOk()
            ->assertSee('Закрытая мини-игра');
    }

    public function test_accepted_responsible_manages_parent_event_and_its_mini_games(): void
    {
        $organizer = User::factory()->create(['username' => 'aggregate-organizer']);
        $responsible = User::factory()->create(['username' => 'aggregate-responsible']);
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($organizer)->post(
            route('events.store'),
            $this->eventPayload($venue, $start, $end, EventTypeEnum::GAME_TRAINING),
        )->assertRedirect();
        $training = Event::query()->where('type', EventTypeEnum::GAME_TRAINING->value)->firstOrFail();
        $this->actingAs($responsible)
            ->post(route('events.join', $training->routeIdentifier()))
            ->assertRedirect();
        $participant = $training->participants()->where('user_id', $responsible->id)->firstOrFail();

        $this->actingAs($organizer)
            ->post(route('events.participants.responsibility.request', [
                $training->routeIdentifier(),
                $participant->id,
            ]))
            ->assertSessionHas('status');
        $this->actingAs($responsible)
            ->patch(route('events.participants.responsibility.respond', [
                $training->routeIdentifier(),
                $participant->id,
            ]), ['decision' => EventResponsibilityStatusEnum::ACCEPTED->value])
            ->assertSessionHas('status');

        $this->actingAs($responsible)
            ->get(route('events.show', $training->routeIdentifier()))
            ->assertOk()
            ->assertSee('Управление мероприятием')
            ->assertSee('Добавить мини-игру');

        $this->actingAs($responsible)
            ->post(route('events.games.store', $training->routeIdentifier()), [
                'title' => 'Мини-игра ответственного',
                'has_scheduled_time' => false,
                'starts_at' => '12:30',
                'ends_at' => '12:30',
                'side_a_name' => 'Светлые',
                'side_b_name' => 'Тёмные',
                'side_a_size' => 1,
                'side_b_size' => 1,
                'side_a_user_ids' => [$organizer->id],
                'side_b_user_ids' => [$responsible->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $miniGame = Event::query()->where('parent_event_id', $training->id)->firstOrFail();
        $this->assertFalse($miniGame->gameDetail->is_time_scheduled);
        $this->assertTrue($miniGame->starts_at->equalTo($training->starts_at));
        $this->assertTrue($miniGame->ends_at->equalTo($training->ends_at));

        $this->actingAs($responsible)
            ->get(route('events.game.manage', $miniGame->routeIdentifier()))
            ->assertOk()
            ->assertSee('name="has_scheduled_time"', false)
            ->assertSee('name="starts_at"', false)
            ->assertSee('name="ends_at"', false)
            ->assertSee('data-mini-game-schedule-input', false)
            ->assertSee('Состав и статистика');

        $this->actingAs($responsible)
            ->put(route('events.game.update', $miniGame->routeIdentifier()), [
                'title' => 'Мини-игра обновлена ответственным',
                'has_scheduled_time' => false,
                'starts_at' => '12:30',
                'ends_at' => '12:30',
                'side_a_name' => 'Светлые',
                'side_b_name' => 'Тёмные',
                'side_a_size' => 1,
                'side_b_size' => 1,
            ])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        $miniGame->refresh();
        $this->assertFalse($miniGame->gameDetail->is_time_scheduled);
        $this->assertTrue($miniGame->starts_at->equalTo($training->starts_at));
        $this->assertTrue($miniGame->ends_at->equalTo($training->ends_at));

        $this->actingAs($responsible)
            ->patch(route('events.game.statistics', $miniGame->routeIdentifier()), [
                'scores' => ['A' => 2, 'B' => 0],
                'players' => [
                    $organizer->id => $this->playerStatistics([
                        'close_made' => 1,
                        'close_attempted' => 1,
                    ]),
                    $responsible->id => $this->playerStatistics(),
                ],
            ])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('events', [
            'id' => $miniGame->id,
            'title' => 'Мини-игра обновлена ответственным',
        ]);
        $this->assertDatabaseHas('game_player_statistics', [
            'event_id' => $miniGame->id,
            'user_id' => $organizer->id,
            'close_made' => 1,
        ]);

        $training->forceFill([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ])->save();
        $miniGame->forceFill([
            'starts_at' => $training->starts_at,
            'ends_at' => $training->ends_at,
        ])->save();

        $this->actingAs($responsible)
            ->patchJson(route('events.game.statistics.complete', $miniGame->routeIdentifier()), [
                'scores' => ['A' => 10, 'B' => 5],
                'players' => [
                    $organizer->id => $this->playerStatistics([
                        'close_made' => 2,
                        'close_attempted' => 3,
                        'three_made' => 2,
                        'three_attempted' => 3,
                    ]),
                    $responsible->id => $this->playerStatistics([
                        'close_made' => 1,
                        'close_attempted' => 2,
                    ]),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('completed', true)
            ->assertJsonPath('scores.A', 10)
            ->assertJsonPath('scores.B', 5);

        $miniGame->refresh();
        $this->assertSame(EventStatusEnum::COMPLETED, $miniGame->status);
        $this->assertNotNull($miniGame->completed_at);
        $this->assertSame(
            GameStatisticsStatusEnum::CONFIRMED,
            $miniGame->gameDetail()->firstOrFail()->statistics_status,
        );
        $this->assertSame(
            2,
            $miniGame->gameRosterEntries()
                ->where('status', GameRosterStatusEnum::PLAYED->value)
                ->count(),
        );
    }

    public function test_existing_mini_game_responsibility_is_moved_to_parent_event(): void
    {
        $organizer = User::factory()->create(['username' => 'legacy-organizer']);
        $responsible = User::factory()->create(['username' => 'legacy-responsible']);
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($organizer)->post(
            route('events.store'),
            $this->eventPayload($venue, $start, $end, EventTypeEnum::GAME_TRAINING),
        )->assertRedirect();
        $training = Event::query()->where('type', EventTypeEnum::GAME_TRAINING->value)->firstOrFail();
        $this->actingAs($responsible)->post(route('events.join', $training->routeIdentifier()));

        $this->actingAs($organizer)
            ->post(route('events.games.store', $training->routeIdentifier()), [
                'title' => 'Мини-игра со старым назначением',
                'side_a_name' => 'Светлые',
                'side_b_name' => 'Тёмные',
                'side_a_size' => 1,
                'side_b_size' => 1,
                'side_a_user_ids' => [$organizer->id],
                'side_b_user_ids' => [$responsible->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $miniGame = Event::query()->where('parent_event_id', $training->id)->firstOrFail();
        $childParticipant = $miniGame->participants()->create([
            'user_id' => $responsible->id,
            'role' => EventParticipantRoleEnum::PARTICIPANT,
            'status' => EventParticipantStatusEnum::CONFIRMED,
            'joined_at' => now(),
            'responsibility_status' => EventResponsibilityStatusEnum::ACCEPTED,
            'responsibility_requested_by_user_id' => $organizer->id,
            'responsibility_requested_at' => now()->subMinute(),
            'responsibility_responded_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_07_30_160000_move_mini_game_responsibilities_to_parent_events.php',
        );
        $migration->up();

        $parentParticipant = $training->participants()
            ->where('user_id', $responsible->id)
            ->firstOrFail();
        $this->assertSame(
            EventResponsibilityStatusEnum::ACCEPTED,
            $parentParticipant->responsibility_status,
        );
        $this->assertNull($childParticipant->refresh()->responsibility_status);

        $this->actingAs($organizer)
            ->from(route('events.show', $miniGame->routeIdentifier()))
            ->post(route('events.participants.responsibility.request', [
                $miniGame->routeIdentifier(),
                $childParticipant->id,
            ]))
            ->assertSessionHas(
                'error',
                'Ответственных назначают на основном мероприятии, а не на отдельной мини-игре.',
            );
    }

    public function test_responsible_with_score_only_permission_cannot_manage_other_mini_game_data(): void
    {
        $organizer = User::factory()->create(['username' => 'permission-organizer']);
        $responsible = User::factory()->create(['username' => 'scorekeeper']);
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($organizer)->post(
            route('events.store'),
            $this->eventPayload($venue, $start, $end, EventTypeEnum::GAME_TRAINING),
        )->assertRedirect();
        $training = Event::query()->where('type', EventTypeEnum::GAME_TRAINING->value)->firstOrFail();
        $this->actingAs($responsible)->post(route('events.join', $training->routeIdentifier()))->assertRedirect();
        $participant = $training->participants()->where('user_id', $responsible->id)->firstOrFail();

        $this->actingAs($organizer)->post(route('events.games.store', $training->routeIdentifier()), [
            'title' => 'Игра со счётчиком',
            'has_scheduled_time' => false,
            'side_a_name' => 'A',
            'side_b_name' => 'B',
            'side_a_size' => 1,
            'side_b_size' => 1,
            'side_a_user_ids' => [$organizer->id],
            'side_b_user_ids' => [$responsible->id],
        ])->assertRedirect();
        $miniGame = Event::query()->where('parent_event_id', $training->id)->firstOrFail();

        $this->actingAs($organizer)->post(route('events.participants.responsibility.request', [
            $training->routeIdentifier(),
            $participant->id,
        ]), [
            'permissions_present' => 1,
            'permissions' => [EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_SCORE->value],
        ])->assertSessionHasNoErrors();
        $this->actingAs($responsible)->patch(route('events.participants.responsibility.respond', [
            $training->routeIdentifier(),
            $participant->id,
        ]), ['decision' => EventResponsibilityStatusEnum::ACCEPTED->value])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('event_responsibility_permissions', [
            'event_participant_id' => $participant->id,
            'permission' => EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_SCORE->value,
        ]);
        $this->actingAs($responsible)->patch(route('events.game.score', $miniGame->routeIdentifier()), [
            'scores' => ['A' => 7, 'B' => 5],
        ])->assertSessionHas('status');
        $this->actingAs($responsible)->put(route('events.game.update', $miniGame->routeIdentifier()), [])
            ->assertForbidden();
        $this->actingAs($responsible)->patch(route('events.game.statistics', $miniGame->routeIdentifier()), [
            'scores' => ['A' => 7, 'B' => 5],
        ])->assertForbidden();
    }

    public function test_organizer_updates_responsibility_permission_snapshot(): void
    {
        $organizer = User::factory()->create();
        $responsible = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(
            route('events.store'),
            $this->eventPayload($venue, $start, $end, EventTypeEnum::TRAINING),
        );
        $event = Event::query()->where('type', EventTypeEnum::TRAINING->value)->firstOrFail();
        $this->actingAs($responsible)->post(route('events.join', $event->routeIdentifier()));
        $participant = $event->participants()->where('user_id', $responsible->id)->firstOrFail();
        $this->actingAs($organizer)->post(route('events.participants.responsibility.request', [
            $event->routeIdentifier(), $participant->id,
        ]))->assertSessionHasNoErrors();

        $this->actingAs($organizer)->put(route('events.participants.responsibility.permissions.update', [
            $event->routeIdentifier(), $participant->id,
        ]), ['permissions' => [EventResponsibilityPermissionEnum::UPDATE_EVENT->value]])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('event_responsibility_permissions', 1);
        $this->assertDatabaseHas('event_responsibility_permissions', [
            'event_participant_id' => $participant->id,
            'permission' => EventResponsibilityPermissionEnum::UPDATE_EVENT->value,
        ]);
    }

    public function test_responsible_cannot_delegate_permission_they_do_not_have(): void
    {
        $organizer = User::factory()->create();
        $manager = User::factory()->create();
        $candidate = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(
            route('events.store'),
            $this->eventPayload($venue, $start, $end, EventTypeEnum::TRAINING),
        );
        $event = Event::query()->where('type', EventTypeEnum::TRAINING->value)->firstOrFail();
        foreach ([$manager, $candidate] as $user) {
            $this->actingAs($user)->post(route('events.join', $event->routeIdentifier()));
        }
        $managerParticipant = $event->participants()->where('user_id', $manager->id)->firstOrFail();
        $candidateParticipant = $event->participants()->where('user_id', $candidate->id)->firstOrFail();

        $this->actingAs($organizer)->post(route('events.participants.responsibility.request', [
            $event->routeIdentifier(), $managerParticipant->id,
        ]), [
            'permissions_present' => 1,
            'permissions' => [EventResponsibilityPermissionEnum::MANAGE_RESPONSIBILITIES->value],
        ]);
        $this->actingAs($manager)->patch(route('events.participants.responsibility.respond', [
            $event->routeIdentifier(), $managerParticipant->id,
        ]), ['decision' => EventResponsibilityStatusEnum::ACCEPTED->value]);

        $this->actingAs($manager)->post(route('events.participants.responsibility.request', [
            $event->routeIdentifier(), $candidateParticipant->id,
        ]), [
            'permissions_present' => 1,
            'permissions' => [
                EventResponsibilityPermissionEnum::MANAGE_RESPONSIBILITIES->value,
                EventResponsibilityPermissionEnum::CANCEL_EVENT->value,
            ],
        ])->assertSessionHas('error', 'Нельзя выдать права, которыми вы не обладаете.');

        $this->assertNull($candidateParticipant->refresh()->responsibility_status);
        $this->assertDatabaseMissing('event_responsibility_permissions', [
            'event_participant_id' => $candidateParticipant->id,
        ]);
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
