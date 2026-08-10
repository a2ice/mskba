<?php

namespace Tests\Feature\Database;

use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tournament\Application\Services\TournamentStandingsService;
use App\Modules\Tournament\Domain\Enums\TournamentPhaseEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Database\Seeders\GameLifecycleDemoSeeder;
use Database\Seeders\TournamentAcceptanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TournamentAcceptanceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_repeatably_creates_standalone_training_and_round_robin_acceptance_data(): void
    {
        $this->seed(TournamentAcceptanceSeeder::class);
        $this->seed(TournamentAcceptanceSeeder::class);

        $tournament = Tournament::query()->where('alias', TournamentAcceptanceSeeder::ALIAS)
            ->with(['entries.members', 'matches.game.sides'])
            ->firstOrFail();
        $this->assertCount(4, $tournament->entries);
        $this->assertSame([3, 3, 3, 3], $tournament->entries->map->members->map->count()->all());
        $this->assertCount(6, $tournament->matches);
        $this->assertSame(2, $tournament->matches->whereNotNull('game_id')->count());
        $this->assertSame(1, $tournament->matches->filter(fn ($match) => $match->game?->statistics_status === GameStatisticsStatusEnum::CONFIRMED)->count());
        $this->assertSame(2, collect(app(TournamentStandingsService::class)->build($tournament))->first()['points']);
        $this->assertSame(TournamentPhaseEnum::ONGOING, $tournament->phase());
        $this->assertFalse($tournament->acceptsAdmissions());

        $completedGame = $tournament->matches->first(fn ($match) => $match->game?->statistics_status === GameStatisticsStatusEnum::CONFIRMED)->game;
        $this->assertTrue($completedGame->scheduled_starts_at->isPast());
        $this->assertTrue($completedGame->event->starts_at->isPast());

        $organizer = User::query()
            ->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $this->actingAs($organizer)->get(route('tournaments.show', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertSee('Турнир · Идёт')
            ->assertSee('Турнирная таблица')
            ->assertSee('12 : 8')
            ->assertDontSee('Подать заявку как игрок');
    }
}
