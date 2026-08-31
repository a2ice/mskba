<?php

namespace Tests\Feature\Tournament;

use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Enums\TournamentEnrollmentPolicyEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPhaseEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContinuousTournamentHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_team_can_self_apply_even_when_incoming_competition_invitations_are_disabled(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $actor = Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $user->id,
        ]);
        $team = Team::query()->create([
            'created_by_actor_id' => $actor->id,
            'name' => 'Opted Out Captains',
            'alias' => 'opted-out-captains',
            'status' => TeamStatusEnum::ACTIVE,
            'accepts_competition_invitations' => false,
        ]);
        $tournament = Tournament::factory()->create([
            'recruitment_mode' => TournamentRecruitmentModeEnum::PREFORMED_TEAMS,
            'enrollment_policy' => TournamentEnrollmentPolicyEnum::CONTINUOUS,
            'starts_on' => today()->subDay(),
            'ends_on' => null,
        ]);

        $response = $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertSee('Подать заявку постоянной командой')
            ->assertSee('Opted Out Captains');

        $this->assertStringContainsString('value="'.$team->id.'"', $response->getContent());
    }

    public function test_direct_entry_creation_after_recruitment_close_does_not_expand_schedule(): void
    {
        $tournament = Tournament::factory()->create([
            'recruitment_mode' => TournamentRecruitmentModeEnum::PREFORMED_TEAMS,
            'enrollment_policy' => TournamentEnrollmentPolicyEnum::CONTINUOUS,
            'round_robin_legs' => 2,
            'starts_on' => today()->subWeek(),
            'ends_on' => null,
        ]);

        $tournament->entries()->create([
            'source' => TournamentEntrySourceEnum::TEAM,
            'name' => 'Alpha',
            'status' => TournamentEntryStatusEnum::ACTIVE,
        ]);
        $tournament->forceFill(['recruitment_closed_at' => now()])->save();

        $tournament->entries()->create([
            'source' => TournamentEntrySourceEnum::TEAM,
            'name' => 'Bravo after close',
            'status' => TournamentEntryStatusEnum::ACTIVE,
        ]);

        $this->assertDatabaseCount('tournament_matches', 0);
    }

    public function test_expired_hard_end_date_closes_admissions_without_auto_finishing_continuous_tournament(): void
    {
        $tournament = Tournament::factory()->create([
            'recruitment_mode' => TournamentRecruitmentModeEnum::PREFORMED_TEAMS,
            'enrollment_policy' => TournamentEnrollmentPolicyEnum::CONTINUOUS,
            'starts_on' => today()->subMonth(),
            'ends_on' => today()->subDay(),
        ]);

        $this->assertFalse($tournament->acceptsAdmissions());
        $this->assertNull($tournament->tournament_closed_at);
        $this->assertSame(TournamentPhaseEnum::ONGOING, $tournament->phase());
    }
}
