<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\LegacyGameRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class EventGameArchitectureTest extends TestCase
{
    use RefreshDatabase;

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
