<?php

namespace Tests\Feature\Database;

use Database\Seeders\TournamentAcceptanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CompetitionAggregateIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_acceptance_data_has_no_orphan_or_cross_aggregate_links(): void
    {
        $this->seed(TournamentAcceptanceSeeder::class);

        $this->assertSame(0, DB::table('events')->where('type', 'game')->whereNull('primary_game_id')->count());
        $this->assertSame(0, DB::table('events as e')->join('games as g', 'g.id', '=', 'e.primary_game_id')->whereColumn('g.event_id', '<>', 'e.id')->count());
        $this->assertSame(0, DB::table('tournament_matches as tm')->join('tournament_entries as a', 'a.id', '=', 'tm.entry_a_id')->join('tournament_entries as b', 'b.id', '=', 'tm.entry_b_id')->where(fn ($query) => $query->whereColumn('a.tournament_id', '<>', 'tm.tournament_id')->orWhereColumn('b.tournament_id', '<>', 'tm.tournament_id'))->count());
        $this->assertSame(0, DB::table('tournament_matches as tm')->join('games as g', 'g.id', '=', 'tm.game_id')->join('events as e', 'e.id', '=', 'g.event_id')->whereColumn('e.primary_game_id', '<>', 'tm.game_id')->count());
        $this->assertSame(0, DB::table('game_roster_entries as gr')->join('games as g', 'g.id', '=', 'gr.game_id')->join('game_sides as gs', 'gs.id', '=', 'gr.game_side_id')->whereColumn('gs.game_id', '<>', 'g.id')->count());
    }
}
