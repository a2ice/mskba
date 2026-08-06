<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameLineupRoleEnum;
use App\Modules\Event\Domain\Models\Event;
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

        $this->setSports($ownerA, $teamA, $ownerAMembership->id, [TeamMemberTypeEnum::PLAYER], true, true);
        $this->setSports($ownerA, $teamA, $playerAMembership->id, [TeamMemberTypeEnum::PLAYER], false, true);
        $this->setSports($ownerA, $teamA, $coachAMembership->id, [TeamMemberTypeEnum::COACH], false, false);
        $this->setSports($ownerB, $teamB, $ownerBMembership->id, [TeamMemberTypeEnum::PLAYER], true, true);
        $this->setSports($ownerB, $teamB, $playerBMembership->id, [TeamMemberTypeEnum::PLAYER], false, true);

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

        $this->setSports($ownerA, $teamA, $playerAMembership->id, [TeamMemberTypeEnum::PLAYER], true, true);
        $this->setSports($ownerA, $teamA, $ownerAMembership->id, [TeamMemberTypeEnum::PLAYER], false, false);

        $this->assertTrue($game->gameRosterEntries()->where('user_id', $ownerA->id)->firstOrFail()->is_captain);
        $this->assertFalse($game->gameRosterEntries()->where('user_id', $playerA->id)->firstOrFail()->is_captain);
    }

    public function test_game_lineup_can_override_defaults_and_locks_on_start(): void
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

        $this->actingAs($ownerA)->putJson(route('events.game.lineup.update', $game->routeIdentifier()), [
            'starters' => ['A' => [$playerA->id], 'B' => [$playerB->id]],
            'captains' => ['A' => $playerA->id, 'B' => $playerB->id],
        ])->assertOk();

        $this->assertDatabaseHas('game_roster_entries', [
            'event_id' => $game->id,
            'user_id' => $playerA->id,
            'lineup_role' => GameLineupRoleEnum::STARTER->value,
            'is_captain' => true,
        ]);

        $this->actingAs($ownerA)
            ->postJson(route('events.game.lifecycle.start', $game->routeIdentifier()))
            ->assertOk();

        $this->actingAs($ownerA)->putJson(route('events.game.lineup.update', $game->routeIdentifier()), [
            'starters' => ['A' => [$ownerA->id], 'B' => [$ownerB->id]],
            'captains' => ['A' => $ownerA->id, 'B' => $ownerB->id],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'После начала игры стартовый состав и капитана изменять нельзя.');
    }

    public function test_only_players_can_be_captain_or_default_starter(): void
    {
        $owner = User::factory()->create(['username' => 'roles-owner']);
        $player = User::factory()->create(['username' => 'roles-player']);
        $coach = User::factory()->create(['username' => 'roles-coach']);
        $team = $this->createTeam($owner, 'Роли команды');
        $ownerMembership = $team->memberships()->where('user_id', $owner->id)->firstOrFail();
        $playerMembership = $this->addMember($owner, $team, $player);
        $coachMembership = $this->addMember($owner, $team, $coach);

        $this->setSports($owner, $team, $ownerMembership->id, [TeamMemberTypeEnum::PLAYER], true, true);
        $this->setSports($owner, $team, $playerMembership->id, [TeamMemberTypeEnum::PLAYER], true, false);

        $this->assertFalse($ownerMembership->fresh()->is_captain);
        $this->assertTrue($playerMembership->fresh()->is_captain);

        $this->actingAs($owner)->put(route('teams.members.sports.update', [
            $team->routeIdentifier(),
            $coachMembership->id,
        ]), [
            'sport_roles' => [TeamMemberTypeEnum::COACH->value],
            'is_captain' => 1,
            'is_default_starter' => 1,
        ])->assertSessionHas('error', 'Капитаном и стартовым участником может быть только игрок.');

        $coachMembership->refresh();
        $this->assertSame(['player'], $coachMembership->sportRoleValues());
        $this->assertFalse($coachMembership->is_captain);
        $this->assertFalse($coachMembership->is_default_starter);
    }

    public function test_score_routes_obey_actual_lifecycle(): void
    {
        [$ownerA, , $teamA] = $this->teamWithPlayer('lifecycle-a');
        [$ownerB, , $teamB] = $this->teamWithPlayer('lifecycle-b');
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'side_a_size' => 1,
            'side_b_size' => 1,
        ])->assertRedirect();

        $game = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();

        $this->actingAs($ownerA)->patchJson(route('events.game.score', $game->routeIdentifier()), [
            'scores' => ['A' => 3, 'B' => 2],
        ])->assertUnprocessable();

        $this->postJson(route('events.game.lifecycle.start', $game->routeIdentifier()))->assertOk();
        $this->patchJson(route('events.game.score', $game->routeIdentifier()), [
            'scores' => ['A' => 3, 'B' => 2],
        ])->assertOk();
        $this->postJson(route('events.game.lifecycle.end', $game->routeIdentifier()))->assertOk();
        $this->patchJson(route('events.game.score', $game->routeIdentifier()), [
            'scores' => ['A' => 4, 'B' => 2],
        ])->assertUnprocessable();
    }

    /** @return array{User, User, Team} */
    private function teamWithPlayer(string $prefix): array
    {
        $owner = User::factory()->create(['username' => $prefix.'-owner']);
        $player = User::factory()->create(['username' => $prefix.'-player']);
        $team = $this->createTeam($owner, $prefix.' team');
        $ownerMembership = $team->memberships()->where('user_id', $owner->id)->firstOrFail();
        $playerMembership = $this->addMember($owner, $team, $player);

        $this->setSports($owner, $team, $ownerMembership->id, [TeamMemberTypeEnum::PLAYER], true, true);
        $this->setSports($owner, $team, $playerMembership->id, [TeamMemberTypeEnum::PLAYER], false, false);

        return [$owner, $player, $team];
    }

    private function createTeam(User $owner, string $name): Team
    {
        $owner->update(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => $name,
            'description' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return Team::query()->where('name', $name)->firstOrFail();
    }

    private function addMember(User $owner, Team $team, User $member)
    {
        $member->update(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->postJson(route('teams.invitations.store', $team->routeIdentifier()), [
            'user_id' => $member->id,
            'member_type' => TeamMemberTypeEnum::PLAYER->value,
        ])->assertCreated();

        $membership = $team->memberships()->where('user_id', $member->id)->firstOrFail();
        $this->actingAs($member)
            ->patch(route('teams.invitations.respond', $membership->id), ['decision' => 'accept'])
            ->assertRedirect();

        return $membership->fresh();
    }

    /** @param list<TeamMemberTypeEnum> $roles */
    private function setSports(
        User $manager,
        Team $team,
        int $membershipId,
        array $roles,
        bool $captain,
        bool $starter,
    ): void {
        $this->actingAs($manager)->put(route('teams.members.sports.update', [
            $team->routeIdentifier(),
            $membershipId,
        ]), [
            'sport_roles' => array_map(static fn (TeamMemberTypeEnum $role): string => $role->value, $roles),
            'is_captain' => $captain,
            'is_default_starter' => $starter,
        ])->assertSessionHas('status')->assertSessionHasNoErrors();
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
