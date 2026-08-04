<?php

namespace Tests\Feature\Event;

use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameLineupRoleEnum;
use App\Modules\Event\Domain\Models\Event;
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

final class GameLineupAndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_defaults_are_copied_to_game_snapshot_and_non_players_are_excluded(): void
    {
        $ownerA = User::factory()->create(['username' => 'defaults-owner-a']);
        $playerA = User::factory()->create(['username' => 'defaults-player-a']);
        $coachA = User::factory()->create(['username' => 'defaults-coach-a']);
        $ownerB = User::factory()->create(['username' => 'defaults-owner-b']);
        $playerB = User::factory()->create(['username' => 'defaults-player-b']);

        $teamA = $this->createTeam($ownerA, 'Состав А');
        $teamB = $this->createTeam($ownerB, 'Состав Б');
        $ownerAMembership = $teamA->memberships()->where('user_id', $ownerA->id)->firstOrFail();
        $playerAMembership = $this->addMember($ownerA, $teamA, $playerA);
        $coachAMembership = $this->addMember($ownerA, $teamA, $coachA);
        $ownerBMembership = $teamB->memberships()->where('user_id', $ownerB->id)->firstOrFail();
        $playerBMembership = $this->addMember($ownerB, $teamB, $playerB);

        $this->setSports($ownerA, $teamA, $ownerAMembership->id, TeamMemberTypeEnum::PLAYER, true, true);
        $this->setSports($ownerA, $teamA, $playerAMembership->id, TeamMemberTypeEnum::PLAYER, false, true);
        $this->setSports($ownerA, $teamA, $coachAMembership->id, TeamMemberTypeEnum::COACH, false, false);
        $this->setSports($ownerB, $teamB, $ownerBMembership->id, TeamMemberTypeEnum::PLAYER, true, true);
        $this->setSports($ownerB, $teamB, $playerBMembership->id, TeamMemberTypeEnum::PLAYER, false, true);

        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'side_a_size' => 2,
            'side_b_size' => 2,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $game = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();
        $entries = $game->gameRosterEntries()->get()->keyBy('user_id');

        $this->assertCount(4, $entries);
        $this->assertFalse($entries->has($coachA->id));
        $this->assertTrue($entries[$ownerA->id]->is_captain);
        $this->assertSame(GameLineupRoleEnum::STARTER, $entries[$ownerA->id]->lineup_role);
        $this->assertSame(GameLineupRoleEnum::STARTER, $entries[$playerA->id]->lineup_role);

        // Later team changes must not rewrite an already created historical snapshot.
        $this->setSports($ownerA, $teamA, $ownerAMembership->id, TeamMemberTypeEnum::PLAYER, false, false);
        $this->setSports($ownerA, $teamA, $playerAMembership->id, TeamMemberTypeEnum::PLAYER, true, true);

        $this->assertTrue($game->gameRosterEntries()->where('user_id', $ownerA->id)->firstOrFail()->is_captain);
        $this->assertFalse($game->gameRosterEntries()->where('user_id', $playerA->id)->firstOrFail()->is_captain);
    }

    public function test_game_lineup_can_override_team_defaults_and_is_locked_on_start(): void
    {
        [$ownerA, $playerA, $teamA] = $this->teamWithPlayer('override-a');
        [$ownerB, $playerB, $teamB] = $this->teamWithPlayer('override-b');
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'side_a_size' => 1,
            'side_b_size' => 1,
        ])->assertRedirect();
        $game = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();

        $this->actingAs($ownerA)->putJson(
            route('events.game.lineup.update', $game->routeIdentifier()),
            [
                'starters' => ['A' => [$playerA->id], 'B' => [$playerB->id]],
                'captains' => ['A' => $playerA->id, 'B' => $playerB->id],
            ],
        )->assertOk();

        $this->assertDatabaseHas('game_roster_entries', [
            'event_id' => $game->id,
            'user_id' => $playerA->id,
            'lineup_role' => GameLineupRoleEnum::STARTER->value,
            'is_captain' => true,
        ]);
        $this->assertDatabaseHas('game_roster_entries', [
            'event_id' => $game->id,
            'user_id' => $ownerA->id,
            'lineup_role' => GameLineupRoleEnum::BENCH->value,
            'is_captain' => false,
        ]);

        $this->actingAs($ownerA)
            ->postJson(route('events.game.lifecycle.start', $game->routeIdentifier()))
            ->assertOk();

        $this->assertSame(
            4,
            $game->gameRosterEntries()->whereNotNull('locked_at')->count(),
        );

        $this->actingAs($ownerA)->putJson(
            route('events.game.lineup.update', $game->routeIdentifier()),
            [
                'starters' => ['A' => [$ownerA->id], 'B' => [$ownerB->id]],
                'captains' => ['A' => $ownerA->id, 'B' => $ownerB->id],
            ],
        )->assertStatus(422)
            ->assertJsonPath('message', 'После начала игры состав и параметры изменять нельзя.');
    }

    public function test_score_and_statistics_routes_obey_actual_lifecycle(): void
    {
        [$ownerA, $playerA, $teamA] = $this->teamWithPlayer('lifecycle-a');
        [$ownerB, $playerB, $teamB] = $this->teamWithPlayer('lifecycle-b');
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'side_a_size' => 1,
            'side_b_size' => 1,
        ])->assertRedirect();
        $game = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();

        $this->actingAs($ownerA)
            ->patchJson(route('events.game.score', $game->routeIdentifier()), [
                'scores' => ['A' => 3, 'B' => 2],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Сначала необходимо начать игру.');

        $this->actingAs($ownerA)
            ->postJson(route('events.game.lifecycle.start', $game->routeIdentifier()))
            ->assertOk();

        $this->actingAs($ownerA)
            ->patchJson(route('events.game.score', $game->routeIdentifier()), [
                'scores' => ['A' => 3, 'B' => 2],
            ])
            ->assertOk();

        $this->actingAs($ownerA)
            ->postJson(route('events.game.statistics.confirm', $game->routeIdentifier()))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Сначала необходимо закончить фактическое проведение игры.');

        $this->actingAs($ownerA)
            ->postJson(route('events.game.lifecycle.end', $game->routeIdentifier()))
            ->assertOk();

        $this->actingAs($ownerA)
            ->patchJson(route('events.game.score', $game->routeIdentifier()), [
                'scores' => ['A' => 4, 'B' => 2],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Игра уже закончена. Оперативный ввод закрыт.');
    }

    public function test_only_player_can_be_captain_or_default_starter_and_new_captain_replaces_old_one(): void
    {
        $owner = User::factory()->create(['username' => 'roles-owner']);
        $player = User::factory()->create(['username' => 'roles-player']);
        $coach = User::factory()->create(['username' => 'roles-coach']);
        $team = $this->createTeam($owner, 'Роли команды');
        $ownerMembership = $team->memberships()->where('user_id', $owner->id)->firstOrFail();
        $playerMembership = $this->addMember($owner, $team, $player);
        $coachMembership = $this->addMember($owner, $team, $coach);

        $this->setSports($owner, $team, $ownerMembership->id, TeamMemberTypeEnum::PLAYER, true, true);
        $this->setSports($owner, $team, $playerMembership->id, TeamMemberTypeEnum::PLAYER, true, false);

        $this->assertFalse($ownerMembership->refresh()->is_captain);
        $this->assertTrue($playerMembership->refresh()->is_captain);

        $this->actingAs($owner)->from(route('teams.edit', $team->routeIdentifier()))
            ->put(route('teams.members.sports.update', [$team->routeIdentifier(), $coachMembership->id]), [
                'member_type' => TeamMemberTypeEnum::COACH->value,
                'is_captain' => 1,
                'is_default_starter' => 1,
            ])
            ->assertSessionHas('error', 'Капитаном и стартовым участником может быть только игрок.');

        $coachMembership->refresh();
        $this->assertNull($coachMembership->member_type);
        $this->assertFalse($coachMembership->is_captain);
        $this->assertFalse($coachMembership->is_default_starter);
    }

    /** @return array{User, User, Team} */
    private function teamWithPlayer(string $prefix): array
    {
        $owner = User::factory()->create(['username' => $prefix.'-owner']);
        $player = User::factory()->create(['username' => $prefix.'-player']);
        $team = $this->createTeam($owner, $prefix.' team');
        $ownerMembership = $team->memberships()->where('user_id', $owner->id)->firstOrFail();
        $playerMembership = $this->addMember($owner, $team, $player);
        $this->setSports($owner, $team, $ownerMembership->id, TeamMemberTypeEnum::PLAYER, true, true);
        $this->setSports($owner, $team, $playerMembership->id, TeamMemberTypeEnum::PLAYER, false, false);

        return [$owner, $player, $team];
    }

    private function createTeam(User $owner, string $name): Team
    {
        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => $name,
            'description' => null,
        ])->assertRedirect();

        return Team::query()->where('name', $name)->firstOrFail();
    }

    private function addMember(User $owner, Team $team, User $member)
    {
        $this->actingAs($owner)->post(route('teams.members.store', $team->routeIdentifier()), [
            'user_id' => $member->id,
            'access_level' => TeamMembershipAccessLevelEnum::PLAYER->value,
        ])->assertSessionHas('status');

        return $team->memberships()->where('user_id', $member->id)->firstOrFail();
    }

    private function setSports(
        User $manager,
        Team $team,
        int $membershipId,
        TeamMemberTypeEnum $type,
        bool $captain,
        bool $starter,
    ): void {
        $this->actingAs($manager)->put(
            route('teams.members.sports.update', [$team->routeIdentifier(), $membershipId]),
            [
                'member_type' => $type->value,
                'is_captain' => $captain,
                'is_default_starter' => $starter,
            ],
        )->assertSessionHas('status')->assertSessionHasNoErrors();
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
            'title' => 'Матч составов',
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
