<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Leagues\LeagueResource;
use App\Filament\Backoffice\Resources\Leagues\Pages\EditLeague;
use App\Filament\Backoffice\Resources\Leagues\RelationManagers\SeasonsRelationManager;
use App\Filament\Backoffice\Resources\Seasons\SeasonResource;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Models\League;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class LeagueSeasonsNestedNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
    }

    private function user(UserSystemRoleEnum $role): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
    }

    private function league(User $owner): League
    {
        return League::query()->create([
            'name' => 'Тестовая лига',
            'alias' => 'test-league-seasons',
            'status' => 'active',
            'owner_user_id' => $owner->id,
        ]);
    }

    public function test_seasons_are_inside_league_and_removed_from_top_level_navigation(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $this->login($superadmin);
        $league = $this->league($superadmin);

        $this->assertFalse(SeasonResource::shouldRegisterNavigation());
        $this->assertContains(SeasonsRelationManager::class, LeagueResource::getRelations());
        $this->assertTrue(SeasonsRelationManager::canViewForRecord($league, EditLeague::class));
        $this->get(LeagueResource::getUrl('edit', ['record' => $league]))->assertOk();

        Livewire::test(SeasonsRelationManager::class, [
            'ownerRecord' => $league,
            'pageClass' => EditLeague::class,
        ])->assertOk()->assertSee('Создать сезон');
    }

    public function test_viewing_league_does_not_grant_season_management_without_permissions(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $manager = $this->user(UserSystemRoleEnum::MANAGER);
        $league = $this->league($superadmin);

        app(BackofficePermissionManager::class)->sync($superadmin, $manager, [
            Permission::BACKOFFICE_ACCESS->value,
            Permission::BACKOFFICE_LEAGUE_VIEW->value,
            Permission::BACKOFFICE_LEAGUE_EDIT->value,
        ], overrideInherited: true);

        $this->login($manager);
        $this->assertTrue(LeagueResource::canView($league));
        $this->assertFalse(SeasonResource::canViewAny());
        $this->assertFalse(SeasonsRelationManager::canViewForRecord($league, EditLeague::class));
        $this->assertFalse(SeasonResource::canCreate());
        $this->assertFalse(SeasonResource::canEdit($league->seasons()->make()));
    }
}
