<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StandaloneGameRecruitmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_delegate_with_game_participation_permission_can_apply_and_outsider_cannot(): void
    {
        $organizer = $this->confirmedUser('game-organizer');
        $owner = $this->confirmedUser('game-team-owner');
        $delegate = $this->confirmedUser('game-team-delegate');
        $outsider = $this->confirmedUser('game-outsider');
        $team = $this->createTeam($owner, 'Permission Team');
        $this->allowGroupInvitations($delegate);

        $this->actingAs($owner)->postJson(route('teams.invitations.store', $team->routeIdentifier()), [
            'user_id' => $delegate->id,
            'member_type' => TeamMemberTypeEnum::PLAYER->value,
            'permissions' => [TeamPermissionEnum::MANAGE_GAME_PARTICIPATION->value],
        ])->assertCreated();
        $membership = $team->memberships()->where('user_id', $delegate->id)->firstOrFail();
        $this->actingAs($delegate)
            ->patch(route('teams.invitations.respond', $membership->id), ['decision' => 'accept'])
            ->assertRedirect();

        $game = $this->createStandaloneGame($organizer, GameRecruitmentModeEnum::PREFORMED_TEAMS);
        $route = [$game->event->routeIdentifier(), $game->id];

        $this->actingAs($outsider)->postJson(route('events.games.recruitment.apply', $route), [
            'team_id' => $team->id,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Недостаточно прав, чтобы представлять этого участника.');

        $this->actingAs($delegate)->postJson(route('events.games.recruitment.apply', $route), [
            'team_id' => $team->id,
        ])->assertOk();

        $admission = $game->admissions()->where('team_id', $team->id)->firstOrFail();
        $this->assertSame(GameAdmissionStatusEnum::PENDING, $admission->status);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $organizer->id,
            'title' => 'Новая заявка на игру',
        ]);
    }

    public function test_application_toggle_blocks_new_applications_but_not_organizer_invitations(): void
    {
        $organizer = $this->confirmedUser('toggle-organizer');
        $player = $this->confirmedUser('toggle-player');
        $this->allowGroupInvitations($player);
        $game = $this->createStandaloneGame($organizer, GameRecruitmentModeEnum::INDIVIDUAL_DRAFT);
        $route = [$game->event->routeIdentifier(), $game->id];

        $this->actingAs($organizer)->patchJson(route('events.games.recruitment.applications', $route), [
            'enabled' => false,
        ])->assertOk();

        $this->actingAs($player)->postJson(route('events.games.recruitment.apply', $route))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Приём новых заявок на эту игру выключен.');

        $this->actingAs($organizer)->postJson(route('events.games.recruitment.invite', $route), [
            'user_id' => $player->id,
        ])->assertOk();
        $admission = $game->admissions()->where('user_id', $player->id)->firstOrFail();

        $this->actingAs($player)->postJson(route('events.games.recruitment.respond', [...$route, $admission->id]), [
            'decision' => 'accepted',
        ])->assertOk();
        $this->assertSame(GameAdmissionStatusEnum::ACCEPTED, $admission->fresh()->status);
    }

    public function test_two_accepted_teams_can_be_confirmed_unconfirmed_and_confirmed_again(): void
    {
        $organizer = $this->confirmedUser('teams-organizer');
        $ownerA = $this->confirmedUser('teams-owner-a');
        $ownerB = $this->confirmedUser('teams-owner-b');
        $teamA = $this->createTeam($ownerA, 'Applicants A');
        $teamB = $this->createTeam($ownerB, 'Applicants B');
        $game = $this->createStandaloneGame($organizer, GameRecruitmentModeEnum::PREFORMED_TEAMS);
        $route = [$game->event->routeIdentifier(), $game->id];

        foreach ([[$ownerA, $teamA], [$ownerB, $teamB]] as [$owner, $team]) {
            $this->actingAs($owner)->postJson(route('events.games.recruitment.apply', $route), ['team_id' => $team->id])->assertOk();
            $admission = $game->admissions()->where('team_id', $team->id)->firstOrFail();
            $this->actingAs($organizer)->postJson(route('events.games.recruitment.respond', [...$route, $admission->id]), [
                'decision' => 'accepted',
            ])->assertOk();
        }

        $this->actingAs($organizer)->postJson(route('events.games.recruitment.teams.confirm', $route), [
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
        ])->assertOk();
        $game->refresh();
        $this->assertNotNull($game->sides_confirmed_at);
        $this->assertSame([$teamA->id, $teamB->id], $game->sides()->orderBy('slot')->pluck('team_id')->all());
        $this->assertDatabaseHas('event_participants', ['event_id' => $game->event_id, 'user_id' => $ownerA->id, 'status' => 'confirmed']);

        $this->actingAs($organizer)->deleteJson(route('events.games.recruitment.unconfirm', $route))->assertOk();
        $game->refresh();
        $this->assertNull($game->sides_confirmed_at);
        $this->assertCount(0, $game->sides);
        $this->assertDatabaseHas('event_participants', ['event_id' => $game->event_id, 'user_id' => $ownerA->id, 'status' => 'left']);

        $this->actingAs($organizer)->postJson(route('events.games.recruitment.teams.confirm', $route), [
            'team_a_id' => $teamB->id,
            'team_b_id' => $teamA->id,
        ])->assertOk();
        $this->assertSame([$teamB->id, $teamA->id], $game->sides()->orderBy('slot')->pluck('team_id')->all());
    }

    public function test_individual_balanced_preview_is_stale_safe_and_materializes_exactly_two_sides(): void
    {
        $organizer = $this->confirmedUser('balanced-organizer');
        $players = collect([
            $this->confirmedUser('balanced-player-1'),
            $this->confirmedUser('balanced-player-2'),
            $this->confirmedUser('balanced-player-3'),
        ]);
        $game = $this->createStandaloneGame($organizer, GameRecruitmentModeEnum::INDIVIDUAL_DRAFT);
        $route = [$game->event->routeIdentifier(), $game->id];

        foreach ($players->take(2) as $player) {
            $this->acceptIndividualApplication($game, $organizer, $player);
        }

        $firstPreview = $this->actingAs($organizer)->postJson(route('events.games.recruitment.formation.preview', $route), [
            'assessment_source' => 'self_assessment',
            'seed' => 105,
        ])->assertOk()->json();
        $this->assertCount(2, $firstPreview['teams']);

        $this->acceptIndividualApplication($game, $organizer, $players[2]);
        $stalePayload = $this->formationPayload($firstPreview);
        $this->actingAs($organizer)->postJson(route('events.games.recruitment.formation.apply', $route), $stalePayload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Пул участников изменился. Сформируйте preview заново.');

        $preview = $this->actingAs($organizer)->postJson(route('events.games.recruitment.formation.preview', $route), [
            'assessment_source' => 'self_assessment',
            'seed' => 105,
        ])->assertOk()->json();
        $secondSameSeed = $this->actingAs($organizer)->postJson(route('events.games.recruitment.formation.preview', $route), [
            'assessment_source' => 'self_assessment',
            'seed' => 105,
        ])->assertOk()->json();
        $this->assertSame($preview['teams'], $secondSameSeed['teams']);

        $this->actingAs($organizer)->postJson(
            route('events.games.recruitment.formation.apply', $route),
            $this->formationPayload($preview),
        )->assertOk();

        $game->refresh();
        $this->assertNotNull($game->sides_confirmed_at);
        $this->assertSame(2, $game->sides()->count());
        $this->assertSame(3, $game->rosterEntries()->count());
        $this->assertSame(3, $game->event->participants()->where('role', 'participant')->count());

        $this->actingAs($organizer)->postJson(route('events.games.start', $route))->assertOk();
        $this->assertSame(GameStatusEnum::IN_PROGRESS, $game->fresh()->status);
        $this->actingAs($organizer)->deleteJson(route('events.games.recruitment.unconfirm', $route))
            ->assertUnprocessable();
    }

    public function test_main_game_configuration_is_editable_before_start_but_size_change_requires_unconfirm(): void
    {
        $organizer = $this->confirmedUser('config-organizer');
        $ownerA = $this->confirmedUser('config-owner-a');
        $ownerB = $this->confirmedUser('config-owner-b');
        $teamA = $this->createTeam($ownerA, 'Config A');
        $teamB = $this->createTeam($ownerB, 'Config B');
        $game = $this->createStandaloneGame(
            $organizer,
            GameRecruitmentModeEnum::PREFORMED_TEAMS,
            teamA: $teamA,
            teamB: $teamB,
        );
        $route = [$game->event->routeIdentifier(), $game->id];

        $this->actingAs($organizer)->putJson(route('events.games.recruitment.configuration', $route), [
            'game_format' => 'streetball_3x3',
            'side_a_size' => 3,
            'side_b_size' => 3,
            'scoring_type' => 'streetball',
            'timing_mode' => 'whole_game',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Сначала снимите утверждение сторон, затем измените размер составов.');

        $this->actingAs($organizer)->deleteJson(route('events.games.recruitment.unconfirm', $route))->assertOk();
        $this->actingAs($organizer)->putJson(route('events.games.recruitment.configuration', $route), [
            'game_format' => 'streetball_3x3',
            'side_a_size' => 3,
            'side_b_size' => 3,
            'scoring_type' => 'streetball',
            'timing_mode' => 'whole_game',
        ])->assertOk();
        $this->assertSame(3, $game->fresh()->side_a_size);
        $this->assertSame(3, $game->fresh()->side_b_size);
    }

    private function acceptIndividualApplication($game, User $organizer, User $player): void
    {
        $route = [$game->event->routeIdentifier(), $game->id];
        $this->actingAs($player)->postJson(route('events.games.recruitment.apply', $route))->assertOk();
        $admission = $game->admissions()->whereIn('user_id', $player->canonical()->identityIds())->latest('id')->firstOrFail();
        $this->actingAs($organizer)->postJson(route('events.games.recruitment.respond', [...$route, $admission->id]), [
            'decision' => 'accepted',
        ])->assertOk();
    }

    /** @param array<string,mixed> $preview @return array<string,mixed> */
    private function formationPayload(array $preview): array
    {
        return [
            'pool_fingerprint' => $preview['pool_fingerprint'],
            'teams' => collect($preview['teams'])->map(fn (array $team): array => [
                'number' => $team['number'],
                'name' => $team['name'],
                'logo_preset' => $team['logo_preset'],
                'user_ids' => collect($team['players'])->pluck('id')->all(),
            ])->all(),
        ];
    }

    private function createStandaloneGame(
        User $organizer,
        GameRecruitmentModeEnum $mode,
        ?Team $teamA = null,
        ?Team $teamB = null,
    ) {
        [$venue, $start] = $this->availableVenue();
        $payload = [
            'venue_id' => $venue->id,
            'title' => 'Recruitment workflow '.$organizer->username,
            'type' => EventTypeEnum::GAME->value,
            'visibility' => 'public',
            'description' => null,
            'starts_at' => $start->format('Y-m-d\\TH:i'),
            'duration_minutes' => 90,
            'max_participants' => 20,
            'game_recruitment_mode' => $mode->value,
            'game_accepts_applications' => true,
            'game_format' => 'streetball_1x1',
            'side_a_size' => 1,
            'side_b_size' => 1,
            'scoring_type' => 'streetball',
            'timing_mode' => 'whole_game',
            'team_a_id' => $teamA?->id,
            'team_b_id' => $teamB?->id,
            'publish_to_telegram' => false,
        ];
        $this->actingAs($organizer)->post(route('events.store'), $payload)->assertRedirect();

        return Event::query()->where('title', $payload['title'])->firstOrFail()->primaryGame()->firstOrFail()->load('event');
    }

    private function confirmedUser(string $username): User
    {
        return User::factory()->create(['username' => $username, 'status' => UserStatusEnum::CONFIRMED]);
    }

    private function createTeam(User $owner, string $name): Team
    {
        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => $name,
            'description' => null,
            'creator_sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
        ])->assertRedirect();

        return Team::query()->where('name', $name)->firstOrFail();
    }

    private function allowGroupInvitations(User $user): void
    {
        $user->privacySettings()->updateOrCreate([
            'type' => UserPrivacySettingTypeEnum::GROUP_INVITATIONS,
        ], [
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);
    }

    /** @return array{Venue, CarbonImmutable} */
    private function availableVenue(): array
    {
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(7)->setTime(12, 0);
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

        return [$venue, $start];
    }
}
