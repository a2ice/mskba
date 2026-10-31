<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Competitions\CompetitionCatalogEditor;
use App\Filament\Backoffice\Resources\Leagues\BackofficeLeagueEditor;
use App\Filament\Backoffice\Resources\Teams\BackofficeTeamEditor;
use App\Filament\Backoffice\Resources\Leagues\LeagueResource;
use App\Filament\Backoffice\Resources\Seasons\BackofficeSeasonEditor;
use App\Filament\Backoffice\Resources\Seasons\SeasonResource;
use App\Filament\Backoffice\Resources\Tournaments\TournamentResource;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Application\Services\TournamentAdmissionService;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CompetitionCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $role): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
    }

    private function login(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
    }

    private function league(string $name = 'Московская лига'): League
    {
        return app(BackofficeLeagueEditor::class)->create([
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function season(League $league, string $name = 'Сезон 2026/27'): LeagueSeason
    {
        return app(BackofficeSeasonEditor::class)->create([
            'league_id' => $league->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    public function test_superadmin_can_open_all_three_crud_sections(): void
    {
        $this->login($this->user(UserSystemRoleEnum::SUPERADMIN));

        foreach ([LeagueResource::class, SeasonResource::class, TournamentResource::class] as $resource) {
            $this->assertTrue($resource::canViewAny());
            $this->assertTrue($resource::canCreate());
            $this->get($resource::getUrl('index'))->assertOk();
            $this->get($resource::getUrl('create'))->assertOk();
        }
    }

    public function test_independent_league_and_optional_season_dates_and_tournament_membership(): void
    {
        $this->login($this->user(UserSystemRoleEnum::SUPERADMIN));
        $league = $this->league();
        $this->assertSame(0, $league->seasons()->count());
        $season = $this->season($league);
        $this->assertNull($season->starts_on);
        $this->assertNull($season->ends_on);
        $editor = app(CompetitionCatalogEditor::class);

        $independent = $editor->createTournament([
            'title' => 'Независимый кубок', 'starts_on' => '2026-11-21',
        ]);
        $this->assertNull($independent->league_id);
        $this->assertNull($independent->league_season_id);

        $leagueOnly = $editor->createTournament([
            'title' => 'Открытый турнир лиги', 'starts_on' => '2026-11-22',
            'league_id' => $league->id,
        ]);
        $this->assertSame($league->id, $leagueOnly->league_id);
        $this->assertNull($leagueOnly->league_season_id);

        $linked = $editor->createTournament([
            'title' => 'Первенство сезона', 'starts_on' => '2026-12-01',
            'league_id' => $league->id, 'league_season_id' => $season->id,
        ]);
        $this->assertSame($season->id, $linked->leagueSeason->id);
        $this->assertSame($league->id, $linked->league->id);
        $this->assertNotEmpty($linked->alias);
        $this->assertSame('unconfirmed', $linked->status->value);
    }

    public function test_season_must_belong_to_selected_league(): void
    {
        $this->login($this->user(UserSystemRoleEnum::SUPERADMIN));
        $first = $this->league('Первая лига');
        $second = $this->league('Вторая лига');
        $season = $this->season($second);

        $this->expectException(ValidationException::class);
        app(CompetitionCatalogEditor::class)->createTournament([
            'title' => 'Несогласованная связь', 'starts_on' => '2026-12-01',
            'league_id' => $first->id, 'league_season_id' => $season->id,
        ]);
    }

    public function test_season_dates_can_be_open_ended_but_not_inverted(): void
    {
        $this->login($this->user(UserSystemRoleEnum::SUPERADMIN));
        $season = $this->season($this->league());
        app(BackofficeSeasonEditor::class)->update($season, [
            'name' => $season->name,
            'status' => 'active',
            'starts_on' => '2026-11-01',
        ]);
        $this->assertNull($season->fresh()->ends_on);

        $this->expectException(ValidationException::class);
        app(BackofficeSeasonEditor::class)->update($season, [
            'name' => $season->name,
            'status' => 'active',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-10-01',
        ]);
    }

    public function test_linked_league_and_season_cannot_be_hard_deleted(): void
    {
        $this->login($this->user(UserSystemRoleEnum::SUPERADMIN));
        $league = $this->league();
        $season = $this->season($league);
        $tournament = app(CompetitionCatalogEditor::class)->createTournament([
            'title' => 'Кубок с историей', 'starts_on' => '2026-12-01',
            'league_id' => $league->id, 'league_season_id' => $season->id,
        ]);

        foreach ([
            fn () => app(BackofficeSeasonEditor::class)->delete($season),
            fn () => app(BackofficeLeagueEditor::class)->delete($league),
        ] as $delete) {
            try {
                $delete();
                $this->fail('A linked competition was deleted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        app(BackofficeLeagueEditor::class)->archive($league);
        app(BackofficeSeasonEditor::class)->archive($season);
        $this->assertSame('archived', $league->fresh()->status);
        $this->assertSame('archived', $season->fresh()->status);
        $this->assertNotNull($tournament->fresh());
    }

    public function test_granular_rights_allow_leagues_but_not_tournaments(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $manager = $this->user(UserSystemRoleEnum::MANAGER);
        $this->login($manager);

        $this->assertFalse(LeagueResource::canViewAny());
        $this->assertFalse(SeasonResource::canViewAny());
        $this->assertFalse(TournamentResource::canViewAny());

        app(BackofficePermissionManager::class)->sync($superadmin, $manager, [
            Permission::BACKOFFICE_ACCESS->value,
            Permission::BACKOFFICE_LEAGUE_VIEW->value,
            Permission::BACKOFFICE_LEAGUE_CREATE->value,
        ], overrideInherited: true);

        $this->assertTrue(LeagueResource::canCreate());
        $this->assertFalse(SeasonResource::canCreate());
        $this->assertFalse(TournamentResource::canCreate());
        $this->get(LeagueResource::getUrl('index'))->assertOk();
        $this->get(SeasonResource::getUrl('index'))->assertForbidden();
        $this->get(TournamentResource::getUrl('index'))->assertForbidden();

        $league = $this->league('Делегированная лига');
        $this->assertInstanceOf(League::class, $league);
        $this->expectException(AuthorizationException::class);
        app(CompetitionCatalogEditor::class)->createTournament([
            'title' => 'Нельзя создать', 'starts_on' => '2026-12-01',
        ]);
    }

    public function test_regular_user_cannot_bypass_tournament_write_permission(): void
    {
        $this->login($this->user(UserSystemRoleEnum::USER));
        $this->expectException(AuthorizationException::class);
        app(CompetitionCatalogEditor::class)->createTournament([
            'title' => 'Запрещённый турнир', 'starts_on' => '2026-12-01',
        ]);
    }
    public function test_superadmin_can_invite_team_without_being_the_tournament_owner(): void
    {
        $creator = $this->user(UserSystemRoleEnum::USER);
        $creatorActor = app(CurrentActorResolver::class)->resolve($creator, null);
        $tournament = Tournament::factory()->create([
            'created_by_actor_id' => $creatorActor->id,
            'status' => 'confirmed',
            'format' => 'basketball_5x5',
            'recruitment_mode' => 'preformed_teams',
            'starts_on' => today()->addDays(10),
        ]);

        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $this->login($super);
        $team = app(BackofficeTeamEditor::class)->create([
            'name' => 'Сборная для приглашений',
            'status' => 'active',
            'sport_types' => ['basketball'],
        ]);
        $actor = app(CurrentActorResolver::class)->resolve($super, null);
        $this->assertNotSame($actor->id, $tournament->created_by_actor_id);

        $admission = app(TournamentAdmissionService::class)->invite($tournament, $actor, $team);
        $this->assertSame(TournamentAdmissionStatusEnum::PENDING, $admission->status);
        $this->assertSame($team->id, $admission->team_id);
        $this->get(TournamentResource::getUrl('edit', ['record' => $tournament]))
            ->assertOk()->assertSee('Заявки и приглашения');
    }

    public function test_tournament_view_permission_does_not_grant_participant_management(): void
    {
        $creator = $this->user(UserSystemRoleEnum::USER);
        $creatorActor = app(CurrentActorResolver::class)->resolve($creator, null);
        $tournament = Tournament::factory()->create([
            'created_by_actor_id' => $creatorActor->id,
            'status' => 'confirmed',
            'format' => 'basketball_5x5',
            'recruitment_mode' => 'preformed_teams',
            'starts_on' => today()->addDays(10),
        ]);
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $this->login($super);
        $team = app(BackofficeTeamEditor::class)->create([
            'name' => 'Команда без допуска',
            'status' => 'active',
            'sport_types' => ['basketball'],
        ]);
        $manager = $this->user(UserSystemRoleEnum::MANAGER);
        app(BackofficePermissionManager::class)->sync($super, $manager, [
            Permission::BACKOFFICE_ACCESS->value,
            Permission::BACKOFFICE_TOURNAMENT_VIEW->value,
        ], overrideInherited: true);
        $this->login($manager);

        $this->assertTrue(TournamentResource::canViewAny());
        $this->assertFalse(TournamentResource::canManageParticipants());
        $this->expectException(\InvalidArgumentException::class);
        app(TournamentAdmissionService::class)->invite(
            $tournament,
            app(CurrentActorResolver::class)->resolve($manager, null),
            $team,
        );
    }

}
