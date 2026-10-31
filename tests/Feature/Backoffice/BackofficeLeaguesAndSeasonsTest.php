<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Leagues\BackofficeLeagueEditor;
use App\Filament\Backoffice\Resources\Leagues\LeagueResource;
use App\Filament\Backoffice\Resources\Seasons\BackofficeSeasonEditor;
use App\Filament\Backoffice\Resources\Seasons\SeasonResource;
use App\Filament\Backoffice\Resources\Teams\BackofficeTeamEditor;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class BackofficeLeaguesAndSeasonsTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(UserSystemRoleEnum $role): User
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        return $user;
    }

    private function league(array $attributes = []): \App\Modules\League\Domain\Models\League
    {
        return app(BackofficeLeagueEditor::class)->create([
            'name' => 'Московская баскетбольная лига',
            'status' => 'active',
            ...$attributes,
        ]);
    }

    public function test_superadmin_has_irrevocable_rights_and_a_manager_needs_explicit_grants(): void
    {
        $super = $this->authenticate(UserSystemRoleEnum::SUPERADMIN);
        $this->assertTrue(LeagueResource::canViewAny());
        $this->assertTrue(LeagueResource::canCreate());
        $this->assertTrue(SeasonResource::canCreate());
        $this->assertContains(Permission::BACKOFFICE_LEAGUE_TEAMS_MANAGE, Permission::backofficeCases());
        $this->assertContains(Permission::BACKOFFICE_SEASON_TEAMS_MANAGE, Permission::backofficeCases());

        UserOperationalPermission::query()->create([
            'user_id' => $super->id, 'permission' => Permission::BACKOFFICE_LEAGUE_CREATE, 'is_allowed' => false,
        ]);
        $this->assertTrue(LeagueResource::canCreate());

        $manager = $this->authenticate(UserSystemRoleEnum::MANAGER);
        $this->assertFalse(LeagueResource::canCreate());
        $this->assertFalse(LeagueResource::canViewAny());
        UserOperationalPermission::query()->create([
            'user_id' => $manager->id, 'permission' => Permission::BACKOFFICE_ACCESS, 'is_allowed' => true,
        ]);
        UserOperationalPermission::query()->create([
            'user_id' => $manager->id, 'permission' => Permission::BACKOFFICE_LEAGUE_VIEW, 'is_allowed' => true,
        ]);
        $this->assertTrue(LeagueResource::canViewAny());
        $this->assertFalse(LeagueResource::canCreate());
    }

    public function test_league_can_live_without_a_season_and_teams_are_shared_between_leagues(): void
    {
        $super = $this->authenticate(UserSystemRoleEnum::SUPERADMIN);
        $team = app(BackofficeTeamEditor::class)->create([
            'name' => 'Команда сезона',
            'status' => 'active',
            'sport_types' => ['basketball'],
        ]);

        $first = $this->league(['team_ids' => [$team->id]]);
        $second = $this->league(['name' => 'Другая лига', 'team_ids' => [$team->id]]);

        $this->assertSame($super->id, $first->owner_user_id);
        $this->assertSame(0, $first->seasons()->count());
        $this->assertSame(1, $first->teams()->count());
        $this->assertSame(2, $team->leagues()->count());
        $this->assertNotSame($first->alias, $second->alias);

        $this->get(LeagueResource::getUrl('index'))->assertOk()->assertSee('Лиги');
        $this->get(SeasonResource::getUrl('index'))->assertOk()->assertSee('Сезоны');
    }

    public function test_season_dates_are_optional_and_only_league_member_teams_can_join(): void
    {
        $this->authenticate(UserSystemRoleEnum::SUPERADMIN);
        $league = $this->league();
        $team = app(BackofficeTeamEditor::class)->create([
            'name' => 'Независимая команда',
            'status' => 'active',
            'sport_types' => ['basketball'],
        ]);
        $season = app(BackofficeSeasonEditor::class)->create([
            'league_id' => $league->id,
            'name' => '2026/27',
            'status' => 'draft',
        ]);
        $this->assertNull($season->starts_on);
        $this->assertNull($season->ends_on);

        $this->expectException(ValidationException::class);
        app(BackofficeSeasonEditor::class)->update($season, [
            'name' => '2026/27',
            'status' => 'draft',
            'team_ids' => [$team->id],
        ]);
    }

    public function test_team_enrollment_and_history_survive_league_archival(): void
    {
        $this->authenticate(UserSystemRoleEnum::SUPERADMIN);
        $league = $this->league();
        $team = app(BackofficeTeamEditor::class)->create([
            'name' => 'Команда участник',
            'status' => 'active',
            'sport_types' => ['basketball'],
        ]);
        app(BackofficeLeagueEditor::class)->update($league, [
            'name' => $league->name,
            'status' => 'active',
            'team_ids' => [$team->id],
        ]);
        $season = app(BackofficeSeasonEditor::class)->create([
            'league_id' => $league->id, 'name' => '2026/27', 'status' => 'active',
            'team_ids' => [$team->id],
        ]);
        app(BackofficeLeagueEditor::class)->archive($league);

        $this->assertSame('archived', $league->fresh()->status);
        $this->assertDatabaseHas('league_season_team', [
            'league_season_id' => $season->id, 'team_id' => $team->id,
        ]);
        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'deleted_at' => null]);
    }
}
