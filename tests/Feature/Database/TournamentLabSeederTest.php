<?php

namespace Tests\Feature\Database;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameLineupRoleEnum;
use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\Participation\PlayerProfile;
use App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment;
use App\Modules\Identity\Domain\Models\UserParticipationRole;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Database\Seeders\TournamentLabSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TournamentLabSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_repeatably_creates_only_the_tournament_creation_prerequisites(): void
    {
        Storage::fake('public');

        $this->seed(TournamentLabSeeder::class);
        $this->seed(TournamentLabSeeder::class);

        $this->assertSame(10, Team::query()->count());
        $this->assertSame(75, PlayerProfile::query()->count());
        $this->assertSame(75, PlayerSelfAssessment::query()->count());
        $this->assertSame(75, UserParticipationRole::query()
            ->where('role', UserParticipationRoleEnum::PLAYER)
            ->where('status', UserParticipationRoleStatusEnum::ACTIVE)
            ->count());
        $this->assertSame(75, Media::query()->where('collection', 'avatar')->where('is_featured', true)->count());
        $this->assertSame(10, Media::query()->where('collection', 'team_logo')->where('is_featured', true)->count());
        $this->assertSame(4, Venue::query()->where('status', VenueStatusEnum::CONFIRMED)->count());
        $this->assertSame(0, Tournament::query()->count());
        $this->assertSame(0, Event::query()->count());
        $this->assertSame(0, Game::query()->count());

        $expectedSizes = [5, 6, 7, 8, 9, 10, 6, 7, 8, 9];
        foreach (Team::query()->with('sportProfiles.lineupMembers')->orderBy('id')->get()->values() as $index => $team) {
            $players = ContractMembership::query()
                ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM)
                ->where('scope_id', $team->id)
                ->whereJsonContains('sport_roles', 'player')
                ->count();
            $lineup = $team->sportProfiles->firstOrFail()->lineupMembers;

            $this->assertSame($expectedSizes[$index], $players);
            $this->assertSame(5, $lineup->where('assignment', TeamLineupAssignmentEnum::STARTER)->count());
            $this->assertSame($expectedSizes[$index] - 5, $lineup->where('assignment', TeamLineupAssignmentEnum::RESERVE)->count());
        }

        $this->assertSame(0, PlayerProfile::query()
            ->whereNull('height_cm')
            ->orWhereNull('weight_kg')
            ->orWhereNull('body_type')
            ->orWhereNull('experience_started_year')
            ->count());
    }

    public function test_game_roster_inherits_permanent_team_lineup_and_captain(): void
    {
        Storage::fake('public');
        $this->seed(TournamentLabSeeder::class);

        $team = Team::query()->with(['sportProfiles.lineupMembers.membership'])->firstOrFail();
        $profile = $team->sportProfiles->firstOrFail();
        $event = Event::factory()->create([
            'type' => EventTypeEnum::GAME,
            'organizer_actor_id' => $team->created_by_actor_id,
        ]);
        $game = $event->games()->create([
            'created_by_actor_id' => $team->created_by_actor_id,
            'status' => GameStatusEnum::SCHEDULED,
            'side_a_size' => 5,
            'side_b_size' => 5,
            'scoring_type' => GameScoringTypeEnum::BASKETBALL,
        ]);
        $side = $game->sides()->create([
            'team_id' => $team->id,
            'slot' => 'A',
            'display_name' => $team->name,
        ]);

        foreach ($profile->lineupMembers as $assignment) {
            $game->rosterEntries()->create([
                'game_side_id' => $side->id,
                'user_id' => $assignment->membership->user_id,
                'source_contract_membership_id' => $assignment->contract_membership_id,
                'status' => GameRosterStatusEnum::SELECTED,
            ]);
        }

        $entries = $game->rosterEntries()->get();
        $this->assertSame(5, $entries->where('lineup_role', GameLineupRoleEnum::STARTER)->count());
        $this->assertSame(
            $profile->lineupMembers->where('assignment', TeamLineupAssignmentEnum::RESERVE)->count(),
            $entries->where('lineup_role', GameLineupRoleEnum::BENCH)->count(),
        );
        $this->assertSame(1, $entries->where('is_captain', true)->count());
    }
}
