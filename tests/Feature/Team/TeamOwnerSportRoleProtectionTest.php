<?php

namespace Tests\Feature\Team;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamOwnerSportRoleProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_owner_and_system_admin_can_edit_owner_sport_roles(): void
    {
        $owner = User::factory()->create([
            'username' => 'protected-team-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $delegate = User::factory()->create([
            'username' => 'delegated-role-manager',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $admin = User::factory()->create([
            'username' => 'system-role-admin',
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда с защищённым владельцем',
            'creator_sport_roles' => ['manager'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $team = Team::query()->where('name', 'Команда с защищённым владельцем')->firstOrFail();
        $ownerMembership = $team->memberships()->where('user_id', $owner->id)->firstOrFail();

        $this->postJson(route('teams.invitations.store', $team->routeIdentifier()), [
            'user_id' => $delegate->id,
            'member_type' => 'manager',
            'permissions' => ['team.roles.manage'],
        ])->assertCreated();

        $delegateMembership = $team->memberships()->where('user_id', $delegate->id)->firstOrFail();
        $this->actingAs($delegate)
            ->patch(route('teams.invitations.respond', $delegateMembership->id), ['decision' => 'accept'])
            ->assertRedirect();

        $ownerUpdateUrl = route('teams.members.sports.update', [
            $team->routeIdentifier(),
            $ownerMembership->id,
        ]);

        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Изменять спортивные роли владельца может только сам владелец или администратор.')
            ->assertDontSee('action="'.$ownerUpdateUrl.'"', false)
            ->assertDontSee('aria-label="Изменить роли protected-team-owner"', false);

        $this->put($ownerUpdateUrl, [
            'sport_roles' => ['coach'],
        ])->assertSessionHas('error', 'Спортивные роли владельца может менять только сам владелец или администратор.');
        $this->assertSame(['manager'], $ownerMembership->fresh()->sportRoleValues());

        $this->actingAs($owner)->put($ownerUpdateUrl, [
            'sport_roles' => ['coach'],
        ])->assertSessionHas('status');
        $this->assertSame(['coach'], $ownerMembership->fresh()->sportRoleValues());

        $this->actingAs($admin)->put($ownerUpdateUrl, [
            'sport_roles' => ['manager'],
        ])->assertSessionHas('status');
        $this->assertSame(['manager'], $ownerMembership->fresh()->sportRoleValues());
    }
}
