<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Application\Services\StandaloneGameFormationService;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
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

final class StandaloneGameRecruitmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_game_can_start_with_one_known_team_without_materializing_half_a_game(): void
    {
        $owner = User::factory()->create();
        $team = $this->createTeam($owner, 'Known team');
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($owner)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end),
            'team_a_id' => $team->id,
            'team_b_id' => null,
            'side_a_size' => 1,
            'side_b_size' => 1,
        ])->assertRedirect();

        $event = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();
        $game = $event->primaryGame()->firstOrFail();

        $this->assertSame(GameRecruitmentModeEnum::PREFORMED_TEAMS, $game->recruitment_mode);
        $this->assertNull($game->sides_confirmed_at);
        $this->assertSame(0, $game->sides()->count());
        $this->assertDatabaseHas('game_admissions', [
            'game_id' => $game->id,
            'team_id' => $team->id,
            'direction' => GameAdmissionDirectionEnum::SELECTION->value,
            'status' => GameAdmissionStatusEnum::ACCEPTED->value,
        ]);

        $this->actingAs($owner)
            ->postJson(route('events.games.start', [$event->routeIdentifier(), $game->id]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Перед началом игры утвердите обе стороны.');

        $this->actingAs($owner)
            ->getJson(route('events.games.lifecycle.show', [$event->routeIdentifier(), $game->id]))
            ->assertOk()
            ->assertJsonPath('sides_confirmed', false)
            ->assertJsonPath('can_start', false);
    }

    public function test_two_selected_teams_keep_existing_direct_creation_flow_and_mark_sides_confirmed(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $teamA = $this->createTeam($ownerA, 'Direct A');
        $teamB = $this->createTeam($ownerB, 'Direct B');
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($ownerA)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end),
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
            'side_a_size' => 1,
            'side_b_size' => 1,
        ])->assertRedirect();

        $game = Event::query()
            ->where('type', EventTypeEnum::GAME->value)
            ->firstOrFail()
            ->primaryGame()
            ->firstOrFail();

        $this->assertNotNull($game->sides_confirmed_at);
        $this->assertSame(2, $game->sides()->count());
        $this->assertSame(2, $game->admissions()->where('status', GameAdmissionStatusEnum::ACCEPTED->value)->count());
        $this->assertSame(2, $game->rosterEntries()->count());
    }

    public function test_individual_draft_starts_without_sides_and_rejects_preselected_team(): void
    {
        $owner = User::factory()->create();
        $team = $this->createTeam($owner, 'Not allowed in draft');
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($owner)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start, $end),
            'game_recruitment_mode' => GameRecruitmentModeEnum::INDIVIDUAL_DRAFT->value,
            'team_a_id' => $team->id,
            'side_a_size' => 1,
            'side_b_size' => 1,
        ])->assertSessionHasErrors('game_recruitment_mode');

        $this->actingAs($owner)->post(route('events.store'), [
            ...$this->eventPayload($venue, $start->addHours(3), $end->addHours(3)),
            'game_recruitment_mode' => GameRecruitmentModeEnum::INDIVIDUAL_DRAFT->value,
            'team_a_id' => null,
            'team_b_id' => null,
            'side_a_size' => 1,
            'side_b_size' => 1,
        ])->assertRedirect();

        $game = Event::query()
            ->where('type', EventTypeEnum::GAME->value)
            ->latest('id')
            ->firstOrFail()
            ->primaryGame()
            ->firstOrFail();

        $this->assertSame(GameRecruitmentModeEnum::INDIVIDUAL_DRAFT, $game->recruitment_mode);
        $this->assertNull($game->sides_confirmed_at);
        $this->assertSame(0, $game->sides()->count());
        $this->assertSame(0, $game->admissions()->count());
    }

    public function test_confirmed_sides_can_be_unconfirmed_before_start(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $teamA = $this->createTeam($ownerA, 'Unlock A');
        $teamB = $this->createTeam($ownerB, 'Unlock B');
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

        app(StandaloneGameFormationService::class)->unconfirm($game, $event->organizerActor);

        $game->refresh();
        $this->assertNull($game->sides_confirmed_at);
        $this->assertSame(0, $game->sides()->count());
        $this->assertSame(0, $game->rosterEntries()->count());
        $this->assertSame(2, $game->admissions()->where('status', GameAdmissionStatusEnum::ACCEPTED->value)->count());

        app(StandaloneGameFormationService::class)->confirmTeams(
            $game,
            $event->organizerActor,
            $teamB->id,
            $teamA->id,
        );

        $game->refresh();
        $this->assertNotNull($game->sides_confirmed_at);
        $this->assertSame($teamB->id, $game->sides()->where('slot', 'A')->value('team_id'));
        $this->assertSame($teamA->id, $game->sides()->where('slot', 'B')->value('team_id'));
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
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(6)->setTime(12, 0);
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
            'title' => 'Набор на самостоятельную игру',
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
