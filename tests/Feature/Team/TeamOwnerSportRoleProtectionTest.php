<?php

namespace Tests\Feature\Team;

use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamOwnerSportRoleProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_roles_are_managed_only_by_owner_in_user_context(): void
    {
        $owner = User::factory()->create([
            'username' => 'protected-team-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $delegate = User::factory()->create([
            'username' => 'delegated-role-manager',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $delegate->privacySettings()->create([
            'type' => UserPrivacySettingTypeEnum::GROUP_INVITATIONS,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
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

        $this->actingAs($delegate)
            ->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()
            ->assertDontSee('action="'.$ownerUpdateUrl.'"', false);

        $this->get(route('teams.management', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Изменять спортивные роли владельца может только сам владелец.')
            ->assertDontSee('action="'.$ownerUpdateUrl.'"', false);

        $this->put($ownerUpdateUrl, ['sport_roles' => ['coach']])
            ->assertSessionHas('error', 'Спортивные роли владельца может менять только сам владелец.');
        $this->assertSame(['manager'], $ownerMembership->fresh()->sportRoleValues());

        $this->actingAs($admin)
            ->get(route('teams.management', $team->routeIdentifier()))
            ->assertForbidden();
        $this->put($ownerUpdateUrl, ['sport_roles' => ['coach']])
            ->assertSessionHas('error', 'Недостаточно прав для управления спортивными ролями команды.');

        $this->actingAs($owner)
            ->get(route('teams.management', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('action="'.$ownerUpdateUrl.'"', false);
        $this->put($ownerUpdateUrl, ['sport_roles' => ['coach']])
            ->assertSessionHas('status');
        $this->assertSame(['coach'], $ownerMembership->fresh()->sportRoleValues());
    }

    public function test_public_team_blocks_follow_multiple_sport_roles(): void
    {
        $owner = User::factory()->create([
            'username' => 'multi-role-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда множественных ролей',
            'creator_sport_roles' => ['manager'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $team = Team::query()->where('name', 'Команда множественных ролей')->firstOrFail();
        $membership = $team->memberships()->where('user_id', $owner->id)->firstOrFail();

        $this->put(route('teams.members.sports.update', [
            $team->routeIdentifier(),
            $membership->id,
        ]), [
            'sport_roles' => ['player', 'coach', 'manager'],
            'is_captain' => '1',
            'is_default_starter' => '1',
        ])->assertSessionHas('status');

        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('multi-role-owner')
            ->assertDontSee('Тренер пока не назначен.')
            ->assertDontSee('Менеджерская роль пока никому не назначена.')
            ->assertSee('Капитан');
    }

    public function test_team_status_is_rejected_by_user_update_even_for_system_admin(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда пользовательского контекста',
            'creator_sport_roles' => ['manager'],
        ])->assertRedirect();
        $team = Team::query()->where('name', 'Команда пользовательского контекста')->firstOrFail();

        $this->put(route('teams.update', $team->routeIdentifier()), [
            'name' => $team->base_name ?? $team->name,
            'description' => 'Новое описание',
            'sport_types' => ['basketball'],
            'status' => TeamStatusEnum::BLOCKED->value,
        ])->assertForbidden();

        $this->actingAs($admin)->put(route('teams.update', $team->routeIdentifier()), [
            'name' => $team->base_name ?? $team->name,
            'description' => 'Попытка администратора',
            'sport_types' => ['basketball'],
            'status' => TeamStatusEnum::BLOCKED->value,
        ])->assertForbidden();

        $this->assertSame(TeamStatusEnum::ACTIVE, $team->fresh()->status);
    }
}
