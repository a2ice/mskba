<?php

namespace Tests\Feature\Tournament;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionCandidateTypeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionDirectionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentAdmission;
use App\Modules\Tournament\Domain\Models\TournamentEntry;
use App\Modules\Tournament\Domain\Models\TournamentMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TournamentEntriesAndMatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_on_one_forces_individual_admission_and_materializes_player_entry_after_acceptance(): void
    {
        $owner = User::factory()->create(['username' => 'owner-1x1', 'status' => UserStatusEnum::CONFIRMED]);
        $player = User::factory()->create(['username' => 'player-1x1', 'status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.store'), $this->payload(GameFormatEnum::STREETBALL_1X1, TournamentRecruitmentModeEnum::PREFORMED_TEAMS));
        $tournament = Tournament::query()->firstOrFail();
        $this->assertSame(TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT, $tournament->recruitment_mode);

        $this->actingAs($player)->post(route('tournaments.admissions.apply', $tournament->routeIdentifier()))
            ->assertSessionHas('status');
        $admission = TournamentAdmission::query()->firstOrFail();
        $this->assertSame(TournamentAdmissionStatusEnum::PENDING, $admission->status);
        $this->assertDatabaseCount('tournament_entries', 0);

        $this->actingAs($owner)->post(route('tournaments.admissions.respond', [$tournament->routeIdentifier(), $admission]), ['decision' => 'accepted'])
            ->assertSessionHas('status');
        $entry = TournamentEntry::query()->with('members')->firstOrFail();
        $this->assertSame(TournamentEntrySourceEnum::INDIVIDUAL, $entry->source);
        $this->assertSame($player->id, $entry->members->sole()->user_id);

        $this->actingAs($player)->post(route('tournaments.admissions.apply', $tournament->routeIdentifier()))
            ->assertSessionHas('error');
        $this->assertDatabaseCount('tournament_admissions', 1);
    }

    public function test_individual_draft_acceptance_builds_pool_without_premature_team_entry(): void
    {
        $owner = User::factory()->create(['username' => 'owner-3x3', 'status' => UserStatusEnum::CONFIRMED]);
        $player = User::factory()->create(['username' => 'player-3x3', 'status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.store'), $this->payload(GameFormatEnum::STREETBALL_3X3, TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT));
        $tournament = Tournament::query()->firstOrFail();
        $this->actingAs($player)->post(route('tournaments.admissions.apply', $tournament->routeIdentifier()));
        $admission = TournamentAdmission::query()->firstOrFail();
        $this->actingAs($owner)->post(route('tournaments.admissions.respond', [$tournament->routeIdentifier(), $admission]), ['decision' => 'accepted']);

        $this->assertSame(TournamentAdmissionStatusEnum::ACCEPTED, $admission->fresh()->status);
        $this->assertDatabaseCount('tournament_entries', 0);

        $changed = $this->payload(GameFormatEnum::STREETBALL_3X3, TournamentRecruitmentModeEnum::PREFORMED_TEAMS);
        $this->actingAs($owner)->put(route('tournaments.update', $tournament->routeIdentifier()), $changed)
            ->assertSessionHas('error', 'Режим набора нельзя менять после первой заявки или приглашения.');
    }

    public function test_started_tournament_rejects_new_application_on_the_server(): void
    {
        $owner = User::factory()->create(['username' => 'owner-started', 'status' => UserStatusEnum::CONFIRMED]);
        $player = User::factory()->create(['username' => 'player-late', 'status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.store'), $this->payload());
        $tournament = Tournament::query()->firstOrFail();
        $tournament->forceFill([
            'starts_on' => today()->subDay(),
            'ends_on' => today()->addDay(),
        ])->save();

        $this->actingAs($player)
            ->get(route('tournaments.show', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertSee('Турнир · Идёт')
            ->assertDontSee('Подать заявку как игрок');

        $this->actingAs($player)
            ->post(route('tournaments.admissions.apply', $tournament->routeIdentifier()))
            ->assertSessionHas('error', 'Приём заявок и приглашений на этот турнир уже закрыт.');

        $this->assertDatabaseCount('tournament_admissions', 0);

        $this->actingAs($owner)
            ->get(route('tournaments.manage', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertSee('Приём заявок и приглашений на этот турнир закрыт.')
            ->assertDontSee('data-tournament-candidate-search', false);
    }

    public function test_tournament_accepts_candidates_on_its_start_date_before_any_game_starts(): void
    {
        $tournament = Tournament::factory()->create([
            'starts_on' => today(),
            'ends_on' => today()->addDay(),
        ]);

        $this->assertTrue($tournament->acceptsAdmissions());
    }

    public function test_manual_matches_keep_unique_stable_sequence_and_reject_foreign_or_same_entry(): void
    {
        $owner = User::factory()->create(['username' => 'owner-matches', 'status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.store'), $this->payload());
        $tournament = Tournament::query()->firstOrFail();
        $entries = collect(['A', 'B', 'C'])->map(fn (string $name) => $tournament->entries()->create([
            'source' => TournamentEntrySourceEnum::ASSEMBLED,
            'name' => $name,
            'status' => TournamentEntryStatusEnum::ACTIVE,
        ]));

        $this->actingAs($owner)->post(route('tournaments.matches.store', $tournament->routeIdentifier()), ['entry_a_id' => $entries[0]->id, 'entry_b_id' => $entries[1]->id, 'round' => 1])->assertSessionHas('status');
        $this->actingAs($owner)->post(route('tournaments.matches.store', $tournament->routeIdentifier()), ['entry_a_id' => $entries[0]->id, 'entry_b_id' => $entries[2]->id, 'round' => 2])->assertSessionHas('status');
        $matches = TournamentMatch::query()->orderBy('sequence')->get();
        $this->assertSame([1, 2], $matches->pluck('sequence')->all());

        $this->actingAs($owner)->patch(route('tournaments.matches.reorder', $tournament->routeIdentifier()), [
            'positions' => [$matches[0]->id => 2, $matches[1]->id => 1],
        ])->assertSessionHas('status');
        $this->assertSame([$matches[1]->id, $matches[0]->id], TournamentMatch::query()->orderBy('sequence')->pluck('id')->all());

        $this->actingAs($owner)->delete(route('tournaments.matches.destroy', [$tournament->routeIdentifier(), $matches[1]]))
            ->assertSessionHas('status');
        $this->assertSame([1], TournamentMatch::query()->pluck('sequence')->all());

        $this->actingAs($owner)->post(route('tournaments.matches.store', $tournament->routeIdentifier()), ['entry_a_id' => $entries[0]->id, 'entry_b_id' => $entries[0]->id])->assertSessionHas('error');
        $foreignTournament = Tournament::factory()->create();
        $foreignEntry = $foreignTournament->entries()->create(['source' => TournamentEntrySourceEnum::ASSEMBLED, 'name' => 'Foreign', 'status' => TournamentEntryStatusEnum::ACTIVE]);
        $this->actingAs($owner)->post(route('tournaments.matches.store', $tournament->routeIdentifier()), ['entry_a_id' => $entries[0]->id, 'entry_b_id' => $foreignEntry->id])->assertSessionHas('error');
        $this->assertDatabaseCount('tournament_matches', 1);
    }

    public function test_balanced_preview_is_repeatable_and_apply_validates_complete_current_pool(): void
    {
        $owner = User::factory()->create(['username' => 'owner-balanced', 'status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.store'), $this->payload());
        $tournament = Tournament::query()->firstOrFail();
        $players = User::factory()->count(6)->create(['status' => UserStatusEnum::CONFIRMED]);
        foreach ($players as $player) {
            $tournament->admissions()->create([
                'candidate_type' => TournamentAdmissionCandidateTypeEnum::USER,
                'user_id' => $player->id,
                'direction' => TournamentAdmissionDirectionEnum::APPLICATION,
                'status' => TournamentAdmissionStatusEnum::ACCEPTED,
                'requested_by_actor_id' => $tournament->created_by_actor_id,
                'responded_by_actor_id' => $tournament->created_by_actor_id,
                'responded_at' => now(),
            ]);
        }
        $payload = ['assessment_source' => 'self_assessment', 'team_count' => 2, 'seed' => 42];
        $first = $this->actingAs($owner)->postJson(route('tournaments.formation.preview', $tournament->routeIdentifier()), $payload)->assertOk()->json();
        $second = $this->actingAs($owner)->postJson(route('tournaments.formation.preview', $tournament->routeIdentifier()), $payload)->assertOk()->json();
        $this->assertSame($first['teams'], $second['teams']);
        $this->assertSame([3, 3], collect($first['teams'])->map(fn (array $team) => count($team['players']))->all());

        $teams = collect($first['teams'])->map(fn (array $team): array => ['number' => $team['number'], 'user_ids' => collect($team['players'])->pluck('id')->all()])->all();
        $this->actingAs($owner)->postJson(route('tournaments.formation.apply', $tournament->routeIdentifier()), ['pool_fingerprint' => $first['pool_fingerprint'], 'teams' => $teams])->assertOk();
        $this->assertSame([3, 3], TournamentEntry::query()->withCount('members')->orderBy('position')->pluck('members_count')->all());

        $teams[0]['user_ids'][] = $teams[1]['user_ids'][0];
        $this->actingAs($owner)->postJson(route('tournaments.formation.apply', $tournament->routeIdentifier()), ['pool_fingerprint' => $first['pool_fingerprint'], 'teams' => $teams])->assertUnprocessable();
    }

    public function test_round_robin_preview_and_apply_cover_every_pair_once_and_are_repeatable(): void
    {
        $owner = User::factory()->create(['username' => 'owner-schedule', 'status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.store'), $this->payload());
        $tournament = Tournament::query()->firstOrFail();
        collect(['A', 'B', 'C', 'D'])->each(fn (string $name, int $position) => $tournament->entries()->create([
            'source' => TournamentEntrySourceEnum::ASSEMBLED,
            'name' => $name,
            'status' => TournamentEntryStatusEnum::ACTIVE,
            'position' => $position + 1,
        ]));

        $first = $this->actingAs($owner)->postJson(route('tournaments.schedule.preview', $tournament->routeIdentifier()), ['legs' => 1])->assertOk()->json();
        $second = $this->actingAs($owner)->postJson(route('tournaments.schedule.preview', $tournament->routeIdentifier()), ['legs' => 1])->assertOk()->json();
        $this->assertSame($first, $second);
        $this->assertCount(3, $first['rounds']);
        $pairs = collect($first['rounds'])->flatMap(fn (array $round) => $round['matches'])
            ->map(fn (array $match): string => collect([$match['entry_a_id'], $match['entry_b_id']])->sort()->join(':'));
        $this->assertCount(6, $pairs);
        $this->assertCount(6, $pairs->unique());

        $this->actingAs($owner)->postJson(route('tournaments.schedule.apply', $tournament->routeIdentifier()), [
            'legs' => 1,
            'entries_fingerprint' => $first['entries_fingerprint'],
        ])->assertOk();
        $this->assertSame(range(1, 6), TournamentMatch::query()->orderBy('sequence')->pluck('sequence')->all());
        $this->assertSame([2, 2, 2], TournamentMatch::query()->get()->groupBy('round')->map->count()->values()->all());

        $double = $this->actingAs($owner)->postJson(route('tournaments.schedule.preview', $tournament->routeIdentifier()), ['legs' => 2])->assertOk()->json();
        $this->actingAs($owner)->postJson(route('tournaments.schedule.apply', $tournament->routeIdentifier()), [
            'legs' => 2,
            'entries_fingerprint' => $double['entries_fingerprint'],
        ])->assertOk();
        $this->assertDatabaseCount('tournament_matches', 12);

        $tournament->entries()->firstOrFail()->update(['name' => 'Изменённая команда']);
        $this->actingAs($owner)->postJson(route('tournaments.schedule.apply', $tournament->routeIdentifier()), [
            'legs' => 2,
            'entries_fingerprint' => $double['entries_fingerprint'],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('tournament_matches', 12);
    }

    public function test_round_robin_supports_bye_and_reversed_second_leg(): void
    {
        $owner = User::factory()->create(['username' => 'owner-double-schedule', 'status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.store'), $this->payload());
        $tournament = Tournament::query()->firstOrFail();
        collect(['A', 'B', 'C'])->each(fn (string $name, int $position) => $tournament->entries()->create([
            'source' => TournamentEntrySourceEnum::ASSEMBLED,
            'name' => $name,
            'status' => TournamentEntryStatusEnum::ACTIVE,
            'position' => $position + 1,
        ]));

        $preview = $this->actingAs($owner)->postJson(route('tournaments.schedule.preview', $tournament->routeIdentifier()), ['legs' => 2])->assertOk()->json();
        $this->assertCount(6, $preview['rounds']);
        $this->assertSame([1, 1, 1, 1, 1, 1], collect($preview['rounds'])->map(fn (array $round): int => count($round['matches']))->all());
        $firstLeg = collect($preview['rounds'])->take(3)->flatMap(fn (array $round) => $round['matches'])->values();
        $secondLeg = collect($preview['rounds'])->skip(3)->flatMap(fn (array $round) => $round['matches'])->values();
        foreach ($firstLeg as $index => $match) {
            $this->assertSame($match['entry_a_id'], $secondLeg[$index]['entry_b_id']);
            $this->assertSame($match['entry_b_id'], $secondLeg[$index]['entry_a_id']);
        }
    }

    /** @return array<string, mixed> */
    private function payload(GameFormatEnum $format = GameFormatEnum::STREETBALL_3X3, TournamentRecruitmentModeEnum $mode = TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT): array
    {
        return [
            'title' => 'Турнир', 'alias' => 'tournament',
            'starts_on' => today()->addWeek()->format('Y-m-d'),
            'ends_on' => today()->addWeeks(2)->format('Y-m-d'),
            'format' => $format->value, 'recruitment_mode' => $mode->value,
        ];
    }
}
