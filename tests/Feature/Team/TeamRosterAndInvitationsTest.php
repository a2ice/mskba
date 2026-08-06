<?php

namespace Tests\Feature\Team;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TeamRosterAndInvitationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_team_names_are_allocated_per_creator(): void
    {
        $firstCreator = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $secondCreator = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($firstCreator)
            ->post(route('teams.store'), ['name' => 'Chicago Bulls'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $first = Team::query()->where('name', 'Chicago Bulls')->firstOrFail();
        $this->assertSame('chicago bulls', $first->normalized_name);
        $this->assertSame(1, $first->name_sequence);

        $this->actingAs($secondCreator)
            ->post(route('teams.store'), ['name' => ' CHICAGO   BULLS '])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $second = Team::query()->where('name_sequence', 2)->firstOrFail();
        $this->assertSame('CHICAGO BULLS №2', $second->name);

        $this->actingAs($firstCreator)
            ->post(route('teams.store'), ['name' => 'chicago bulls'])
            ->assertSessionHasErrors('name');
    }

    public function test_creator_manages_independent_sport_rosters(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $players = $team->memberships()
            ->withSportRole(TeamMemberTypeEnum::PLAYER)
            ->orderBy('id')
            ->get();

        $this->actingAs($creator)->putJson(route('teams.roster.update', $team->routeIdentifier()), [
            'sport_type' => 'basketball',
            'starter_ids' => $players->take(4)->pluck('id')->all(),
            'reserve_ids' => $players->slice(4)->pluck('id')->all(),
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'В основном составе «Баскетбол» должно быть 5 игроков.');

        $this->putJson(route('teams.roster.update', $team->routeIdentifier()), [
            'sport_type' => 'basketball',
            'starter_ids' => $players->take(5)->pluck('id')->reverse()->values()->all(),
            'reserve_ids' => $players->slice(5)->pluck('id')->all(),
        ])->assertOk()
            ->assertJsonPath('message', 'Состав сохранён.');

        $this->assertDatabaseHas('team_sport_lineup_members', [
            'contract_membership_id' => $players[4]->id,
            'assignment' => 'starter',
        ]);
    }

    public function test_invitation_acceptance_adds_player_and_inline_permissions_are_rendered(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $candidate = User::factory()->create([
            'username' => 'invited-player',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $candidate->profile()->create(['first_name' => 'Новый', 'last_name' => 'Игрок']);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();

        $this->actingAs($creator)->postJson(route('teams.invitations.store', $team->routeIdentifier()), [
            'user_id' => $candidate->id,
            'member_type' => TeamMemberTypeEnum::PLAYER->value,
            'permissions' => ['team.roster.manage'],
        ])->assertCreated();

        $membership = $team->memberships()->where('user_id', $candidate->id)->firstOrFail();
        $this->assertSame(['player'], $membership->sportRoleValues());
        $this->assertSame(TeamInvitationStatusEnum::PENDING, $membership->invitation_status);
        $this->assertSame(ContractStatusEnum::INACTIVE, $membership->contract->status);

        $this->actingAs($candidate)
            ->patch(route('teams.invitations.respond', $membership->id), ['decision' => 'accept'])
            ->assertRedirect();

        $membership->refresh();
        $this->assertSame(TeamInvitationStatusEnum::ACCEPTED, $membership->invitation_status);
        $this->assertSame(ContractStatusEnum::ACTIVE, $membership->contract->fresh()->status);
        $this->assertSame(2, DB::table('team_sport_lineup_members')
            ->where('contract_membership_id', $membership->id)
            ->count());

        $this->actingAs($creator)->putJson(route('teams.members.permissions', [
            $team->routeIdentifier(),
            $membership->id,
        ]), [
            'permissions' => ['team.roster.manage', 'team.members.remove'],
        ])->assertOk();

        $this->get(route('teams.management', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Права участника')
            ->assertSee('Исключать участников из команды')
            ->assertDontSee('data-modal-target="team-member-permissions-'.$membership->id.'"', false);
    }

    public function test_removing_a_player_updates_contract_and_lineups(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $player = $team->memberships()
            ->withSportRole(TeamMemberTypeEnum::PLAYER)
            ->where('is_captain', false)
            ->where('user_id', '!=', $creator->id)
            ->firstOrFail();

        $this->actingAs($creator)
            ->deleteJson(route('teams.members.destroy', [$team->routeIdentifier(), $player->id]))
            ->assertOk()
            ->assertJsonPath('membership_id', $player->id);

        $this->assertSame(ContractStatusEnum::INACTIVE, $player->contract->fresh()->status);
        $this->assertDatabaseMissing('team_sport_lineup_members', [
            'contract_membership_id' => $player->id,
        ]);
    }

    public function test_team_with_too_few_players_can_save_partial_roster(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $team = Team::query()->where('alias', 'demo-blue')->firstOrFail();
        $players = $team->memberships()
            ->withSportRole(TeamMemberTypeEnum::PLAYER)
            ->orderBy('id')
            ->get();

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
            ->assertOk()
            ->assertSee('Неполный состав');
    }

    public function test_team_creation_permission_can_be_revoked(): void
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

    public function test_catalog_uses_compact_sport_labels_and_incomplete_state(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);

        $this->get(route('teams.index'))
            ->assertOk()
            ->assertSee('title="Баскетбол"', false)
            ->assertSee('title="Стритбол"', false)
            ->assertSee('class="is-sport__short" aria-hidden="true">5x5', false)
            ->assertSee('class="is-sport__short" aria-hidden="true">3x3', false)
            ->assertSee('Неполный состав');
    }

    public function test_deleted_unbound_team_moves_to_draft(): void
    {
        $creator = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($creator)
            ->post(route('teams.store'), ['name' => 'Команда без мероприятий'])
            ->assertRedirect();

        $team = Team::query()->where('name', 'Команда без мероприятий')->firstOrFail();
        $this->delete(route('teams.destroy', $team->routeIdentifier()))
            ->assertRedirect(route('account.teams'));

        $this->assertSame(TeamStatusEnum::DRAFT, $team->fresh()->status);
    }
}
