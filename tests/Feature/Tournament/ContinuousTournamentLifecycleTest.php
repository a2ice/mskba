<?php

namespace Tests\Feature\Tournament;

use App\Modules\Tournament\Application\Services\TournamentLifecycleService;
use App\Modules\Tournament\Application\Services\TournamentParticipantPoolService;
use App\Modules\Tournament\Domain\Enums\TournamentEnrollmentPolicyEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPhaseEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContinuousTournamentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_continuous_tournament_keeps_recruitment_open_until_explicitly_closed(): void
    {
        $tournament = Tournament::factory()->create([
            'recruitment_mode' => TournamentRecruitmentModeEnum::PREFORMED_TEAMS,
            'enrollment_policy' => TournamentEnrollmentPolicyEnum::CONTINUOUS,
            'starts_on' => today()->subWeek(),
            'ends_on' => null,
        ]);
        $actor = $tournament->createdByActor;

        $this->assertTrue($tournament->acceptsAdmissions());
        $this->assertSame(TournamentPhaseEnum::ONGOING, $tournament->phase());

        app(TournamentParticipantPoolService::class)->lock($tournament, $actor);
        $tournament->refresh();

        $this->assertNotNull($tournament->recruitment_closed_at);
        $this->assertNull($tournament->participant_pool_locked_at);
        $this->assertFalse($tournament->acceptsAdmissions());
        $this->assertSame(TournamentPhaseEnum::ONGOING, $tournament->phase());

        app(TournamentParticipantPoolService::class)->unlock($tournament, $actor);
        $tournament->refresh();

        $this->assertNull($tournament->recruitment_closed_at);
        $this->assertTrue($tournament->acceptsAdmissions());
    }

    public function test_new_continuous_team_appends_only_missing_round_robin_matches(): void
    {
        $tournament = Tournament::factory()->create([
            'recruitment_mode' => TournamentRecruitmentModeEnum::PREFORMED_TEAMS,
            'enrollment_policy' => TournamentEnrollmentPolicyEnum::CONTINUOUS,
            'round_robin_legs' => 2,
            'starts_on' => today()->subWeek(),
            'ends_on' => null,
        ]);

        $entryA = $tournament->entries()->create([
            'source' => TournamentEntrySourceEnum::TEAM,
            'name' => 'Alpha',
            'status' => TournamentEntryStatusEnum::ACTIVE,
        ]);
        $entryB = $tournament->entries()->create([
            'source' => TournamentEntrySourceEnum::TEAM,
            'name' => 'Bravo',
            'status' => TournamentEntryStatusEnum::ACTIVE,
        ]);

        $initialMatches = $tournament->matches()->orderBy('sequence')->get();
        $this->assertCount(2, $initialMatches);
        $this->assertSame([1, 2], $initialMatches->pluck('sequence')->all());
        $initialIds = $initialMatches->pluck('id')->all();

        $entryC = $tournament->entries()->create([
            'source' => TournamentEntrySourceEnum::TEAM,
            'name' => 'Charlie',
            'status' => TournamentEntryStatusEnum::ACTIVE,
        ]);

        $matches = $tournament->matches()->orderBy('sequence')->get();
        $this->assertCount(6, $matches);
        $this->assertSame(range(1, 6), $matches->pluck('sequence')->all());
        $this->assertSame($initialIds, $matches->take(2)->pluck('id')->all());
        $this->assertCount(4, $matches->filter(fn ($match): bool => in_array($entryC->id, [$match->entry_a_id, $match->entry_b_id], true)));

        foreach ([$entryA, $entryB] as $existing) {
            $pair = $matches->filter(fn ($match): bool => collect([$match->entry_a_id, $match->entry_b_id])->sort()->values()->all()
                === collect([$existing->id, $entryC->id])->sort()->values()->all());
            $this->assertCount(2, $pair);
            $this->assertSame([1, 2], $pair->pluck('round')->sort()->values()->all());
        }
    }

    public function test_explicit_close_finishes_open_ended_continuous_tournament_and_closes_recruitment(): void
    {
        $tournament = Tournament::factory()->create([
            'recruitment_mode' => TournamentRecruitmentModeEnum::PREFORMED_TEAMS,
            'enrollment_policy' => TournamentEnrollmentPolicyEnum::CONTINUOUS,
            'starts_on' => today()->subMonth(),
            'ends_on' => null,
        ]);
        $actor = $tournament->createdByActor;

        app(TournamentLifecycleService::class)->close($tournament, $actor);
        $tournament->refresh();

        $this->assertNotNull($tournament->recruitment_closed_at);
        $this->assertNotNull($tournament->tournament_closed_at);
        $this->assertSame($actor->id, $tournament->recruitment_closed_by_actor_id);
        $this->assertSame($actor->id, $tournament->tournament_closed_by_actor_id);
        $this->assertFalse($tournament->acceptsAdmissions());
        $this->assertSame(TournamentPhaseEnum::COMPLETED, $tournament->phase());
    }

    public function test_individual_draft_cannot_behave_as_continuous_even_if_requested_in_data(): void
    {
        $tournament = Tournament::factory()->create([
            'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            'enrollment_policy' => TournamentEnrollmentPolicyEnum::FIXED_POOL,
            'starts_on' => today()->subDay(),
            'ends_on' => today()->addWeek(),
        ]);

        $this->assertFalse($tournament->isContinuous());
        $this->assertTrue($tournament->acceptsAdmissions());
    }
}
