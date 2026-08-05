<?php

namespace Tests\Feature\Team;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TeamRosterAndInvitationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_change_status_and_creator_delete_moves_unbound_team_to_draft(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();

        $this->actingAs($creator)->get(route('teams.edit', $team->routeIdentifier()))
            ->assertOk()
            ->assertDontSee('value="blocked"', false)
            ->assertDontSee('value="archived"', false)
            ->assertSee('Удалить команду');
        $this->put(route('teams.update', $team->routeIdentifier()), [
            'name' => $team->name,
            'description' => $team->description,
            'status' => TeamStatusEnum::BLOCKED->value,
        ])->assertForbidden();
        $this->assertSame(TeamStatusEnum::ACTIVE, $team->fresh()->status);
        $this->delete(route('teams.destroy', $team->routeIdentifier()))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(TeamStatusEnum::ACTIVE, $team->fresh()->status);

        $this->post(route('teams.store'), ['name' => 'Команда без мероприятий'])->assertRedirect();
        $unboundTeam = Team::query()->where('name', 'Команда без мероприятий')->firstOrFail();
        $this->delete(route('teams.destroy', $unboundTeam->routeIdentifier()))
            ->assertRedirect(route('account.teams'));
        $this->assertSame(TeamStatusEnum::DRAFT, $unboundTeam->fresh()->status);

        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $this->actingAs($admin)->get(route('teams.edit', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('value="blocked"', false)
            ->assertSee('value="archived"', false);
        $this->put(route('teams.update', $team->routeIdentifier()), [
            'name' => $team->name,
            'description' => $team->description,
            'status' => TeamStatusEnum::ARCHIVED->value,
        ])->assertRedirect();
        $this->assertSame(TeamStatusEnum::ARCHIVED, $team->fresh()->status);
    }

    public function test_admin_creator_sees_delete_button_and_non_creator_manager_cannot_delete_team(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin)
            ->post(route('teams.store'), ['name' => 'Команда администратора'])
            ->assertRedirect();
        $team = Team::query()->where('name', 'Команда администратора')->firstOrFail();

        $this->get(route('teams.edit', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Удалить команду')
            ->assertSee('Вы уверены, что хотите удалить команду?', false);

        $otherAdmin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $this->actingAs($otherAdmin)
            ->delete(route('teams.destroy', $team->routeIdentifier()))
            ->assertForbidden();
        $this->assertSame(TeamStatusEnum::ACTIVE, $team->fresh()->status);
    }

    public function test_creator_manages_independent_sport_rosters_and_incomplete_roster_is_rejected_when_players_are_available(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $team = Team::query()->where('alias', 'demo-red')->with('memberships')->firstOrFail();
        $players = $team->memberships->where('member_type.value', 'player')->values();

        $this->actingAs($creator)->putJson(route('teams.roster.update', $team->routeIdentifier()), [
            'sport_type' => 'basketball',
            'starter_ids' => $players->take(4)->pluck('id')->all(),
            'reserve_ids' => $players->slice(4)->pluck('id')->all(),
        ])->assertUnprocessable()->assertJsonPath('message', 'В основном составе «Баскетбол» должно быть 5 игроков.');

        $this->putJson(route('teams.roster.update', $team->routeIdentifier()), [
            'sport_type' => 'basketball',
            'starter_ids' => $players->take(6)->pluck('id')->all(),
            'reserve_ids' => $players->slice(6)->pluck('id')->all(),
        ])->assertUnprocessable()->assertJsonPath('message', 'В основном составе «Баскетбол» не может быть больше 5 игроков.');

        $this->putJson(route('teams.roster.update', $team->routeIdentifier()), [
            'sport_type' => 'basketball',
            'starter_ids' => $players->take(5)->pluck('id')->reverse()->values()->all(),
            'reserve_ids' => $players->slice(5)->pluck('id')->all(),
        ])->assertOk()->assertJsonPath('message', 'Состав сохранён.');
        $this->assertDatabaseHas('team_sport_lineup_members', [
            'contract_membership_id' => $players[4]->id,
            'assignment' => 'starter',
        ]);
    }

    public function test_invitation_requires_acceptance_and_then_adds_player_to_each_sport_reserve(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $candidate = User::factory()->create(['username' => 'invited-player', 'status' => UserStatusEnum::CONFIRMED]);
        $candidate->profile()->create(['first_name' => 'Новый', 'last_name' => 'Игрок']);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();

        $this->actingAs($creator)->postJson(route('teams.invitations.store', $team->routeIdentifier()), [
            'user_id' => $candidate->id,
            'member_type' => 'player',
            'permissions' => ['team.roster.manage'],
        ])->assertCreated();
        $membership = $team->memberships()->where('user_id', $candidate->id)->firstOrFail();
        $this->assertSame(TeamInvitationStatusEnum::PENDING, $membership->invitation_status);
        $this->assertSame(ContractStatusEnum::INACTIVE, $membership->contract->status);

        $this->actingAs($candidate)->get(route('account.teams'))
            ->assertOk()->assertSee('Ожидает подтверждения')->assertSee('Принять');
        $this->patch(route('teams.invitations.respond', $membership->id), ['decision' => 'accept'])->assertRedirect();
        $this->assertSame(TeamInvitationStatusEnum::ACCEPTED, $membership->fresh()->invitation_status);
        $this->assertSame(ContractStatusEnum::ACTIVE, $membership->contract->fresh()->status);
        $this->assertSame(2, DB::table('team_sport_lineup_members')->where('contract_membership_id', $membership->id)->count());

        $this->actingAs($creator)->putJson(route('teams.members.permissions', [$team->routeIdentifier(), $membership->id]), [
            'permissions' => ['team.roster.manage', 'team.members.remove'],
        ])->assertOk()->assertJsonPath('message', 'Договорные права обновлены.');
        $this->assertDatabaseHas('contract_permissions', [
            'contract_id' => $membership->contract_id,
            'permission' => 'team.members.remove',
        ]);
        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('data-modal-target="team-member-permissions-'.$membership->id.'"', false)
            ->assertSee('Права участника')
            ->assertSee('Исключать участников из команды');

        $this->actingAs($candidate)->deleteJson(route('teams.members.destroy', [$team->routeIdentifier(), $membership->id]))
            ->assertUnprocessable();
        $captain = $team->memberships()->where('is_captain', true)->firstOrFail();
        $this->deleteJson(route('teams.members.destroy', [$team->routeIdentifier(), $captain->id]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Капитана нельзя исключить из команды. Сначала назначьте другого капитана.');
        $removablePlayer = $team->memberships()->where('member_type', 'player')->where('is_captain', false)->where('user_id', '!=', $candidate->id)->firstOrFail();
        $this->deleteJson(route('teams.members.destroy', [$team->routeIdentifier(), $removablePlayer->id]))
            ->assertOk()->assertJsonPath('membership_id', $removablePlayer->id);
        $this->assertSame(ContractStatusEnum::INACTIVE, $removablePlayer->contract->fresh()->status);
        $this->assertDatabaseMissing('team_sport_lineup_members', ['contract_membership_id' => $removablePlayer->id]);

        $playerIds = $team->memberships()->where('member_type', 'player')->whereHas('contract', fn ($query) => $query->where('status', 'active'))->orderBy('id')->pluck('id');
        $this->putJson(route('teams.roster.update', $team->routeIdentifier()), [
            'sport_type' => 'streetball',
            'starter_ids' => $playerIds->take(3)->all(),
            'reserve_ids' => $playerIds->slice(3)->values()->all(),
        ])->assertOk();
    }

    public function test_team_creation_operational_permission_requires_confirmed_active_account_and_can_be_revoked(): void
    {
        $unconfirmed = User::factory()->create();
        $confirmed = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($unconfirmed)->get(route('teams.create'))->assertForbidden();
        $this->actingAs($confirmed)->get(route('teams.create'))->assertOk();
        UserOperationalPermission::create([
            'user_id' => $confirmed->id,
            'permission' => UserOperationalPermissionEnum::CREATE_TEAM,
            'is_allowed' => false,
        ]);
        $this->get(route('teams.create'))->assertForbidden();
    }

    public function test_team_with_too_few_players_can_save_partial_starters_and_is_marked_incomplete(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $team = Team::query()->where('alias', 'demo-blue')->with('memberships')->firstOrFail();
        $players = $team->memberships->where('member_type.value', 'player')->values();
        foreach ($players->slice(2) as $player) {
            $player->sportLineupAssignments()->delete();
            $player->contract->update(['status' => ContractStatusEnum::INACTIVE]);
        }

        $this->actingAs($creator)->putJson(route('teams.roster.update', $team->routeIdentifier()), [
            'sport_type' => 'basketball',
            'starter_ids' => $players->take(2)->pluck('id')->all(),
            'reserve_ids' => [],
        ])->assertOk();
        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()->assertSee('Неполный состав');
    }

    public function test_team_catalog_renders_full_and_compact_sport_labels_in_status_row(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);

        $this->get(route('teams.index'))
            ->assertOk()
            ->assertSee('catalog-toolbar teams-catalog-toolbar', false)
            ->assertSee('name="q"', false)
            ->assertSee('form="team-catalog-filter-form"', false)
            ->assertSee('catalog-toolbar__button-text">Фильтры', false)
            ->assertSee('catalog-card team-catalog-card', false)
            ->assertSee('catalog-card__badges team-catalog-card__badges', false)
            ->assertSee('title="Баскетбол"', false)
            ->assertSee('title="Стритбол"', false)
            ->assertSee('data-tooltip-variant="title"', false)
            ->assertSee('class="is-sport__short" aria-hidden="true">5x5', false)
            ->assertSee('class="is-sport__short" aria-hidden="true">3x3', false)
            ->assertSee('team-catalog-card__meta', false)
            ->assertSee('team-catalog-card__member-count', false)
            ->assertDontSee('team-catalog-card__tags', false);
    }
}
