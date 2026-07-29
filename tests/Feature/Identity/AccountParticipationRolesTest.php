<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountParticipationRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_or_update_account_roles(): void
    {
        $this->get(route('account.roles'))->assertRedirect(route('login'));
        $this->patch(route('account.roles.update'), [])->assertRedirect(route('login'));
    }

    public function test_user_can_open_roles_page_and_profile_links_to_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.roles'))
            ->assertOk()
            ->assertSee('Роли в проекте')
            ->assertSee('Игрок')
            ->assertSee('Представитель площадки');

        $this->actingAs($user)
            ->get(route('account'))
            ->assertOk()
            ->assertSee('Выбрать роль')
            ->assertSee(route('account.roles'), false);
    }

    public function test_user_can_activate_deactivate_and_reactivate_multiple_roles(): void
    {
        $user = User::factory()->create();
        $existingRole = $user->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now()->subDay(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);

        $this->actingAs($user)
            ->patch(route('account.roles.update'), $this->rolePayload([
                UserParticipationRoleEnum::COACH,
                UserParticipationRoleEnum::MEDIA,
            ]))
            ->assertRedirect(route('account.roles'))
            ->assertSessionHas('status', 'Роли в проекте обновлены.');

        $this->assertDatabaseHas('user_participation_roles', [
            'id' => $existingRole->id,
            'status' => UserParticipationRoleStatusEnum::INACTIVE->value,
        ]);
        $this->assertDatabaseHas('user_participation_roles', [
            'user_id' => $user->id,
            'role' => UserParticipationRoleEnum::COACH->value,
            'status' => UserParticipationRoleStatusEnum::ACTIVE->value,
        ]);
        $this->assertDatabaseHas('user_participation_roles', [
            'user_id' => $user->id,
            'role' => UserParticipationRoleEnum::MEDIA->value,
            'status' => UserParticipationRoleStatusEnum::ACTIVE->value,
        ]);

        $this->actingAs($user)
            ->patch(route('account.roles.update'), $this->rolePayload([
                UserParticipationRoleEnum::PLAYER,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_participation_roles', [
            'id' => $existingRole->id,
            'status' => UserParticipationRoleStatusEnum::ACTIVE->value,
            'expires_at' => null,
        ]);
        $this->assertSame(
            3,
            $user->participationRoles(false)->count(),
            'Reactivation must reuse the existing unique role row.',
        );
    }

    public function test_user_can_clear_all_roles_and_unknown_role_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('account.roles.update'), $this->rolePayload([]))
            ->assertSessionHasNoErrors();

        $invalidPayload = $this->rolePayload([]);
        $invalidPayload['roles']['owner'] = '1';

        $this->actingAs($user)
            ->patch(route('account.roles.update'), $invalidPayload)
            ->assertSessionHasErrors('roles');
    }

    /**
     * @param  array<int, UserParticipationRoleEnum>  $selectedRoles
     * @return array{roles: array<string, string>}
     */
    private function rolePayload(array $selectedRoles): array
    {
        $selectedValues = collect($selectedRoles)->map->value->all();

        return [
            'roles' => collect(UserParticipationRoleEnum::cases())
                ->mapWithKeys(fn (UserParticipationRoleEnum $role): array => [
                    $role->value => in_array($role->value, $selectedValues, true) ? '1' : '0',
                ])
                ->all(),
        ];
    }
}
