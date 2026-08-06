<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
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

        $game = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();

        $this->actingAs($ownerA)
            ->getJson(route('events.game.lifecycle.show', $game->routeIdentifier()))
            ->assertOk()
            ->assertJsonPath('started', false)
            ->assertJsonPath('ended', false)
            ->assertJsonPath('can_start', true)
            ->assertJsonPath('can_end', false)
            ->assertJsonPath('can_enter_statistics', false)
            ->assertJsonPath('can_manage_score', false);

        $this->actingAs($ownerA)
            ->postJson(route('events.game.lifecycle.start', $game->routeIdentifier()))
            ->assertOk()
            ->assertJsonPath('message', 'Игра началась.');

        $game->refresh();
        $this->assertNotNull($game->actual_started_at);
        $this->assertNotNull($game->actual_started_by_actor_id);
        $this->assertNull($game->actual_ended_at);
        $this->assertSame(
            GameStatisticsStatusEnum::ENTERING,
            $game->gameDetail()->firstOrFail()->statistics_status,
        );

        $this->actingAs($ownerA)
            ->getJson(route('events.game.lifecycle.show', $game->routeIdentifier()))
            ->assertOk()
            ->assertJsonPath('started', true)
            ->assertJsonPath('ended', false)
            ->assertJsonPath('can_start', false)
            ->assertJsonPath('can_end', true)
            ->assertJsonPath('can_enter_statistics', true)
            ->assertJsonPath('can_manage_score', true);

        $this->actingAs($ownerA)
            ->postJson(route('events.game.lifecycle.start', $game->routeIdentifier()))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Игра уже началась.');

        $this->actingAs($ownerA)
            ->postJson(route('events.game.lifecycle.end', $game->routeIdentifier()))
            ->assertOk();

        $game->refresh();
        $this->assertNotNull($game->actual_ended_at);
        $this->assertNotNull($game->actual_ended_by_actor_id);
        $this->assertTrue($game->actual_ended_at->greaterThanOrEqualTo($game->actual_started_at));
        $this->assertSame(EventStatusEnum::PUBLISHED, $game->status);
        $this->assertSame(
            GameStatisticsStatusEnum::READY,
            $game->gameDetail()->firstOrFail()->statistics_status,
        );

        $this->actingAs($ownerA)
            ->getJson(route('events.game.lifecycle.show', $game->routeIdentifier()))
            ->assertOk()
            ->assertJsonPath('started', true)
            ->assertJsonPath('ended', true)
            ->assertJsonPath('can_end', false)
            ->assertJsonPath('can_enter_statistics', false)
            ->assertJsonPath('can_manage_score', false)
            ->assertJsonPath('can_confirm_result', true);

        $this->actingAs($ownerA)
            ->postJson(route('events.game.lifecycle.end', $game->routeIdentifier()))
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

        $game = Event::query()->where('type', EventTypeEnum::GAME->value)->firstOrFail();

        $this->actingAs($ownerA)
            ->postJson(route('events.game.lifecycle.end', $game->routeIdentifier()))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Сначала необходимо начать игру.');
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
