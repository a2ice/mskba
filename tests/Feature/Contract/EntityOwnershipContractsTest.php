<?php

namespace Tests\Feature\Contract;

use App\Filament\Backoffice\Resources\Competitions\CompetitionCatalogEditor;
use App\Filament\Backoffice\Resources\Leagues\BackofficeLeagueEditor;
use App\Filament\Backoffice\Resources\Leagues\LeagueResource;
use App\Filament\Backoffice\Resources\Seasons\BackofficeSeasonEditor;
use App\Filament\Backoffice\Resources\Teams\BackofficeTeamEditor;
use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Enums\LeaguePermissionEnum;
use App\Modules\League\Domain\Enums\SeasonPermissionEnum;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Tournament\Application\Services\TournamentAccess;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class EntityOwnershipContractsTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
    }

    private function office(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
    }

    private function membership(Scope $scope, int $id, string $role = 'owner'): ContractMembership
    {
        return ContractMembership::query()->with('contract.permissions')
            ->where('scope_type', $scope->value)->where('scope_id', $id)
            ->where('access_level', $role)->firstOrFail();
    }

    public function test_public_league_creation_requires_global_permission_and_issues_own_contract(): void
    {
        $regular = $this->user();
        $this->actingAs($regular)->get(route('leagues.create'))->assertForbidden();

        $creator = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($creator)->get(route('leagues.create'))->assertOk();
        $response = $this->post(route('leagues.store'), [
            'name' => 'Любительская лига',
            'description' => 'Московская баскетбольная лига',
        ]);
        $entity = \App\Modules\League\Domain\Models\League::query()
            ->where('name', 'Любительская лига')->firstOrFail();
        $response->assertRedirect(route('leagues.manage', $entity->id));
        $contract = $this->membership(Scope::LEAGUE, $entity->id);
        $this->assertSame($creator->id, $contract->user_id);
        $this->assertSame('accepted', $contract->invitation_status->value);

        $other = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($other)->get(route('leagues.manage', $entity->id))->assertForbidden();
    }

    public function test_public_season_creation_requires_league_contract_and_assumes_league_owner(): void
    {
        $owner = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $other = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($owner)->post(route('leagues.store'), ['name' => 'Лига новых сезонов']);
        $league = \App\Modules\League\Domain\Models\League::query()->where('name', 'Лига новых сезонов')->firstOrFail();

        $this->actingAs($other)->post(route('leagues.seasons.store', $league->id), [
            'name' => 'Весна 2027',
        ])->assertForbidden();

        $this->actingAs($owner)->post(route('leagues.seasons.store', $league->id), [
            'name' => 'Весна 2027',
        ])->assertRedirect();
        $season = \App\Modules\League\Domain\Models\LeagueSeason::query()->where('name', 'Весна 2027')->firstOrFail();
        $contract = $this->membership(Scope::SEASON, $season->id);
        $this->assertSame($owner->id, $contract->user_id);
        $this->assertSame('accepted', $contract->invitation_status->value);
    }

    public function test_backoffice_global_edit_is_independent_from_public_contracts(): void
    {
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $owner = $this->user();
        $this->office($admin);
        $league = app(BackofficeLeagueEditor::class)->create([
            'name' => 'Лига контрактов', 'status' => 'active', 'owner_user_id' => $owner->id,
        ]);
        $membership = $this->membership(Scope::LEAGUE, $league->id);
        $contracts = app(EntityContractManager::class);

        $this->assertSame($owner->id, $membership->user_id);
        $this->assertSame('pending', $membership->invitation_status->value);
        $this->assertTrue(LeagueResource::canEdit($league));
        $this->assertFalse($contracts->allows($admin, Scope::LEAGUE, $league->id, LeaguePermissionEnum::EDIT->value));
        $this->assertFalse($contracts->allows($owner, Scope::LEAGUE, $league->id, LeaguePermissionEnum::EDIT->value));
        $this->assertCount(count(LeaguePermissionEnum::cases()), $membership->contract->permissions);

        $contracts->respond($membership, $owner, true);
        $this->assertTrue($contracts->allows($owner, Scope::LEAGUE, $league->id, LeaguePermissionEnum::EDIT->value));
    }

    public function test_season_defaults_to_league_owner_even_when_backoffice_user_creates_it(): void
    {
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $owner = $this->user();
        $this->office($admin);
        $league = app(BackofficeLeagueEditor::class)->create([
            'name' => 'Региональная лига', 'status' => 'active', 'owner_user_id' => $owner->id,
        ]);
        $season = app(BackofficeSeasonEditor::class)->create([
            'league_id' => $league->id, 'name' => 'Осень 2026', 'status' => 'active',
        ]);
        $membership = $this->membership(Scope::SEASON, $season->id);
        $this->assertSame($owner->id, $membership->user_id);
        $this->assertCount(count(SeasonPermissionEnum::cases()), $membership->contract->permissions);
    }

    public function test_tournament_assigned_to_a_different_owner_does_not_grant_the_creator_public_access(): void
    {
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $owner = $this->user();
        $this->office($admin);
        $tournament = app(CompetitionCatalogEditor::class)->createTournament([
            'title' => 'Турнир контрактов',
            'starts_on' => today()->addDays(10)->format('Y-m-d'),
            'owner_user_id' => $owner->id,
        ]);
        $access = app(TournamentAccess::class);
        $creatorActor = app(CurrentActorResolver::class)->resolve($admin, null);
        $this->assertFalse($access->allows($tournament, $creatorActor, TournamentPermissionEnum::MANAGE_STAFF));

        app(EntityContractManager::class)->respond($this->membership(Scope::TOURNAMENT, $tournament->id), $owner, true);
        $ownerActor = app(CurrentActorResolver::class)->resolve($owner, null);
        $this->assertTrue($access->allows($tournament, $ownerActor, TournamentPermissionEnum::MANAGE_STAFF));
    }

    public function test_team_created_in_backoffice_has_owner_snapshot_and_no_creator_shortcut(): void
    {
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $owner = $this->user();
        $this->office($admin);
        $team = app(BackofficeTeamEditor::class)->create([
            'name' => 'Новая сборная', 'status' => 'active',
            'sport_types' => ['basketball'], 'owner_user_id' => $owner->id,
        ]);
        $membership = $this->membership(Scope::TEAM, $team->id);
        $this->assertCount(count(TeamPermissionEnum::cases()), $membership->contract->permissions);
        $access = app(TeamManagementAccess::class);
        $this->assertFalse($access->allows($team, app(CurrentActorResolver::class)->resolve($admin, null),
            TeamPermissionEnum::EDIT_SETTINGS));
        app(EntityContractManager::class)->respond($membership, $owner, true);
        $this->assertTrue($access->allows($team, app(CurrentActorResolver::class)->resolve($owner, null),
            TeamPermissionEnum::EDIT_SETTINGS));
    }

    public function test_named_invitation_requires_acceptance_and_cannot_delegate_unheld_rights(): void
    {
        $owner = $this->user();
        $delegate = $this->user();
        $other = $this->user();
        $this->office($this->user(UserSystemRoleEnum::SUPERADMIN));
        $entity = app(BackofficeLeagueEditor::class)->create([
            'name' => 'Лига выдачи прав', 'status' => 'active', 'owner_user_id' => $owner->id,
        ]);
        $contracts = app(EntityContractManager::class);
        $contracts->respond($this->membership(Scope::LEAGUE, $entity->id), $owner, true);
        $invite = $contracts->invite(Scope::LEAGUE, $entity->id, $owner, $delegate,
            'Спортивный координатор', [LeaguePermissionEnum::EDIT->value]);
        $this->assertSame('Спортивный координатор', $invite->contract->name);
        $this->assertFalse($contracts->allows($delegate, Scope::LEAGUE, $entity->id, LeaguePermissionEnum::EDIT->value));
        $contracts->respond($invite, $delegate, true);
        $this->assertTrue($contracts->allows($delegate, Scope::LEAGUE, $entity->id, LeaguePermissionEnum::EDIT->value));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $contracts->invite(Scope::LEAGUE, $entity->id, $delegate, $other,
            'Незаконная выдача прав', [LeaguePermissionEnum::MANAGE_CONTRACTS->value]);
    }

    public function test_ownership_transfer_is_atomic_and_revokes_old_owner_after_acceptance(): void
    {
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $old = $this->user();
        $new = $this->user();
        $this->office($admin);
        $league = app(BackofficeLeagueEditor::class)->create([
            'name' => 'Лига передачи', 'status' => 'active', 'owner_user_id' => $old->id,
        ]);
        $contracts = app(EntityContractManager::class);
        $contracts->respond($this->membership(Scope::LEAGUE, $league->id), $old, true);
        $pending = $contracts->transferOwner(Scope::LEAGUE, $league->id, $old, $new);
        $this->assertFalse($contracts->allows($new, Scope::LEAGUE, $league->id, LeaguePermissionEnum::EDIT->value));
        $this->assertTrue($contracts->allows($old, Scope::LEAGUE, $league->id, LeaguePermissionEnum::EDIT->value));
        $contracts->respond($pending, $new, true);
        $this->assertSame($new->id, $league->fresh()->owner_user_id);
        $this->assertTrue($contracts->allows($new, Scope::LEAGUE, $league->id, LeaguePermissionEnum::EDIT->value));
        $this->assertFalse($contracts->allows($old, Scope::LEAGUE, $league->id, LeaguePermissionEnum::EDIT->value));
    }

    public function test_venue_primary_manager_contract_is_not_verified_commercial_ownership(): void
    {
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $manager = $this->user();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($admin, null)->id,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
        ]);
        $contracts = app(EntityContractManager::class);
        $membership = $contracts->grantOwner(Scope::VENUE, $venue->id, $manager, $admin,
            'Главный ответственный');
        $this->assertSame('primary_manager', $membership->access_level);
        $this->assertFalse(app(VenueMembershipAccess::class)->hasActiveOwner($venue));
        $contracts->respond($membership, $manager, true);
        $this->assertFalse(app(VenueAccessResolver::class)->canEdit($admin, $venue));
        $this->assertTrue($contracts->allows($manager, Scope::VENUE, $venue->id, VenuePermissionEnum::EDIT->value));
        $this->assertFalse($contracts->allows($manager, Scope::VENUE, $venue->id, VenuePermissionEnum::MANAGE_FUNDS->value));
        $this->assertFalse($contracts->allows($manager, Scope::VENUE, $venue->id, VenuePermissionEnum::MANAGE_MEMBERSHIPS->value));
    }
}
