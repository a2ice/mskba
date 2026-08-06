<?php

namespace Tests\Feature\Team;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TeamRosterAndInvitationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_manages_independent_sport_rosters(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $players = $team->memberships()->withSportRole(TeamMemberTypeEnum::PLAYER)->orderBy('id')->get();

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
        ])->assertOk()->assertJsonPath('message', 'Состав сохранён.');

        $this->assertDatabaseHas('team_sport_lineup_members', [
            'contract_membership_id' => $players[4]->id,
            'assignment' => 'starter',
        ]);
    }

    public function test_invitation_acceptance_adds_player_to_each_sport(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $candidate = User::factory()->create([
            'username' => 'invited-player',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $candidate->profile()->create(['first_name' => 'Новый', 'last_name' => 'Игрок']);
        $this->allowGroupInvitations($candidate);
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
    }

    public function test_inline_member_permissions_replace_the_old_modal(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $candidate = User::factory()->create([
            'username' => 'inline-permissions-player',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $this->allowGroupInvitations($candidate);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();

        $this->actingAs($creator)->postJson(route('teams.invitations.store', $team->routeIdentifier()), [
            'user_id' => $candidate->id,
            'member_type' => TeamMemberTypeEnum::PLAYER->value,
        ])->assertCreated();
        $membership = $team->memberships()->where('user_id', $candidate->id)->firstOrFail();
        $this->actingAs($candidate)
            ->patch(route('teams.invitations.respond', $membership->id), ['decision' => 'accept'])
            ->assertRedirect();

        $this->actingAs($creator)
            ->get(route('teams.management', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Права участника')
            ->assertDontSee('data-modal-target="team-member-permissions-'.$membership->id.'"', false);
    }

    public function test_removing_player_deactivates_contract_and_clears_lineups(): void
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
            ->assertOk();

        $this->assertSame(ContractStatusEnum::INACTIVE, $player->contract->fresh()->status);
        $this->assertDatabaseMissing('team_sport_lineup_members', [
            'contract_membership_id' => $player->id,
        ]);
    }

    public function test_catalog_renders_full_and_compact_sport_labels(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);

        $this->get(route('teams.index'))
            ->assertOk()
            ->assertSee('title="Баскетбол"', false)
            ->assertSee('title="Стритбол"', false)
            ->assertSee('class="is-sport__short" aria-hidden="true">5x5', false)
            ->assertSee('class="is-sport__short" aria-hidden="true">3x3', false);
    }

    private function allowGroupInvitations(User $user): void
    {
        $user->privacySettings()->updateOrCreate([
            'type' => UserPrivacySettingTypeEnum::GROUP_INVITATIONS,
        ], [
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);
    }
}
