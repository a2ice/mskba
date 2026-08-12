<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameActionTypeEnum;
use App\Modules\Event\Domain\Enums\GamePeriodStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\LegacyGameRoute;
use App\Modules\Event\Infrastructure\Broadcasting\GameLiveUpdated;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class EventGameArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_game_live_broadcast_uses_versioned_public_channel_contract(): void
    {
        $broadcast = new GameLiveUpdated(42);

        $this->assertSame('game.live.42', $broadcast->broadcastOn()[0]->name);
        $this->assertSame('game.live.updated', $broadcast->broadcastAs());
        $this->assertSame(42, $broadcast->broadcastWith()['game_id']);
    }

    public function test_legacy_game_schema_is_removed(): void
    {
        $this->assertFalse(Schema::hasTable('game_details'));
        $this->assertFalse(Schema::hasColumn('events', 'parent_event_id'));
        $this->assertFalse(Schema::hasColumn('events', 'actual_started_at'));
        $this->assertFalse(Schema::hasColumn('games', 'legacy_event_id'));

        foreach (['game_sides', 'game_roster_entries', 'game_player_statistics'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'game_id'));
            $this->assertFalse(Schema::hasColumn($table, 'event_id'));
        }
    }

    public function test_games_and_sides_are_isolated_inside_one_event(): void
    {
        $event = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $first = $this->game($event, 'Первая игра');
        $second = $this->game($event, 'Вторая игра');

        $first->sides()->createMany([
            ['slot' => 'A', 'display_name' => 'Красные', 'score' => 11],
            ['slot' => 'B', 'display_name' => 'Синие', 'score' => 8],
        ]);
        $second->sides()->createMany([
            ['slot' => 'A', 'display_name' => 'Белые', 'score' => 4],
            ['slot' => 'B', 'display_name' => 'Чёрные', 'score' => 6],
        ]);

        $this->assertCount(2, $event->games);
        $this->assertSame([11, 8], $first->sides()->orderBy('slot')->pluck('score')->all());
        $this->assertSame([4, 6], $second->sides()->orderBy('slot')->pluck('score')->all());
        $this->assertSame($event->id, $first->event_id);
        $this->assertSame($event->id, $second->event_id);
    }

    public function test_nested_route_rejects_game_from_another_event(): void
    {
        $firstEvent = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $secondEvent = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $game = $this->game($secondEvent, 'Чужая игра');

        $this->get(route('events.games.show', [$firstEvent->routeIdentifier(), $game->id]))
            ->assertNotFound();
    }

    public function test_game_live_route_renders_scoreboard_for_owning_event(): void
    {
        $event = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $game = $this->game($event, 'Live игра');
        $game->sides()->createMany([
            ['slot' => 'A', 'display_name' => 'Красные', 'score' => 11],
            ['slot' => 'B', 'display_name' => 'Синие', 'score' => 8],
        ]);

        $this->get(route('events.games.live', [$event->routeIdentifier(), $game->id]))
            ->assertOk()
            ->assertSee('Live игра')
            ->assertSee('Красные')
            ->assertSee('Синие')
            ->assertSee('data-game-live-score="A"', false)
            ->assertSee('data-game-live-score="B"', false);
    }

    public function test_game_live_route_displays_active_period(): void
    {
        $event = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $game = $this->game($event, 'Игра по периодам');
        $game->update([
            'timing_mode' => GameTimingModeEnum::PERIODS,
            'periods_count' => 4,
        ]);
        $game->periods()->createMany([
            ['number' => 1, 'status' => GamePeriodStatusEnum::COMPLETED],
            ['number' => 2, 'status' => GamePeriodStatusEnum::IN_PROGRESS],
            ['number' => 3, 'status' => GamePeriodStatusEnum::SCHEDULED],
            ['number' => 4, 'status' => GamePeriodStatusEnum::SCHEDULED],
        ]);

        $this->get(route('events.games.live', [$event->routeIdentifier(), $game->id]))
            ->assertOk()
            ->assertSee('ПЕРИОД 2 ИЗ 4')
            ->assertSee('data-game-live-active-period="2"', false);
    }

    public function test_game_live_snapshot_exposes_stable_public_contract(): void
    {
        $event = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $game = $this->game($event, 'Snapshot game');
        $game->update([
            'timing_mode' => GameTimingModeEnum::PERIODS,
            'periods_count' => 4,
            'actual_started_at' => now(),
        ]);
        $permanentTeam = Team::query()->create([
            'created_by_actor_id' => $event->organizer_actor_id,
            'name' => 'Постоянная команда',
            'alias' => 'permanent-live-team',
        ]);
        $permanentTeam->media()->create([
            'collection' => 'team_logo',
            'disk' => 'public',
            'path' => 'teams/permanent-live-team.webp',
            'is_featured' => true,
        ]);
        $game->sides()->createMany([
            ['slot' => 'A', 'display_name' => 'Красные', 'logo_preset' => 'crest-03', 'score' => 7],
            ['slot' => 'B', 'team_id' => $permanentTeam->id, 'display_name' => 'Синие', 'score' => 5],
        ]);
        $game->periods()->create([
            'number' => 1,
            'status' => GamePeriodStatusEnum::IN_PROGRESS,
            'side_a_score' => 7,
            'side_b_score' => 5,
        ]);

        $url = route('events.games.live.snapshot', [$event->routeIdentifier(), $game->id]);
        $response = $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('schema', 1)
            ->assertJsonPath('game_id', $game->id)
            ->assertJsonPath('scores.A', 7)
            ->assertJsonPath('scores.B', 5)
            ->assertJsonPath('timing.active_period', 1)
            ->assertJsonPath('teams.A.name', 'Красные')
            ->assertJsonPath('teams.A.logo', '/images/tournament-team-logos/crest-03.webp')
            ->assertJsonPath('teams.B.name', 'Синие')
            ->assertJsonPath('teams.B.logo', '/storage/teams/permanent-live-team.webp')
            ->assertJsonStructure(['revision', 'generated_at', 'status', 'scores', 'timing', 'teams']);

        $this->assertSame($response->json('revision'), $this->getJson($url)->json('revision'));
    }

    public function test_game_live_snapshot_uses_spectator_friendly_shot_labels(): void
    {
        $event = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $game = $this->game($event, 'Live action labels');
        $side = $game->sides()->create(['slot' => 'A', 'display_name' => 'Красные']);
        $url = route('events.games.live.snapshot', [$event->routeIdentifier(), $game->id]);

        $actions = [
            [GameActionTypeEnum::SHOT_MADE, 1, 'close', '1 point'],
            [GameActionTypeEnum::SHOT_MADE, 2, 'mid', '2 points'],
            [GameActionTypeEnum::SHOT_MADE, 3, 'three', '3 points'],
            [GameActionTypeEnum::SHOT_MADE, 1, 'free_throw', 'Штрафной'],
            [GameActionTypeEnum::SHOT_MISSED, 0, 'three', 'Мимо'],
        ];

        foreach ($actions as $index => [$type, $points, $range, $label]) {
            $game->actions()->create([
                'sequence' => $index + 1,
                'game_side_id' => $side->id,
                'type' => $type,
                'points' => $points,
                'payload' => ['range' => $range],
                'occurred_at' => now(),
            ]);

            $this->getJson($url)
                ->assertOk()
                ->assertJsonPath('latest_action.label', $label)
                ->assertJsonPath('latest_action.team_logo', '/images/tournament-team-logos/crest-00.webp');
        }
    }

    public function test_game_live_route_rejects_game_from_another_event(): void
    {
        $firstEvent = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $secondEvent = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $game = $this->game($secondEvent, 'Чужой live');

        $this->get(route('events.games.live', [$firstEvent->routeIdentifier(), $game->id]))
            ->assertNotFound();
    }

    public function test_published_legacy_url_redirects_to_canonical_game_route(): void
    {
        $event = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $game = $this->game($event, 'Перенесённая игра');
        LegacyGameRoute::query()->create([
            'legacy_event_id' => 999999,
            'legacy_identifier' => '999999-old-mini-game',
            'game_id' => $game->id,
        ]);

        $this->get(route('events.show', '999999-old-mini-game'))
            ->assertRedirect(route('events.games.show', [$event->routeIdentifier(), $game->id]))
            ->assertStatus(301);
    }

    private function game(Event $event, string $title): Game
    {
        return Game::query()->create([
            'event_id' => $event->id,
            'created_by_actor_id' => $event->organizer_actor_id,
            'title' => $title,
            'status' => GameStatusEnum::SCHEDULED,
            'side_a_size' => 3,
            'side_b_size' => 3,
        ]);
    }
}
