<?php

namespace Tests\Feature\Admin;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_change_another_users_status(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER, UserStatusEnum::UNCONFIRMED);

        $this->actingAs($superadmin)
            ->post(route('admin.users.status.update', $target), [
                'status' => UserStatusEnum::BLOCKED->value,
            ])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('success');

        $this->assertSame(UserStatusEnum::BLOCKED, $target->refresh()->status);
    }

    public function test_admin_cannot_change_user_status_or_deletion_state(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);

        $this->actingAs($admin)
            ->post(route('admin.users.status.update', $target), ['status' => UserStatusEnum::BLOCKED->value])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.users.bulk-delete'), ['user_ids' => [$target->id]])
            ->assertForbidden();
    }

    public function test_superadmin_can_soft_delete_filter_and_restore_users(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);

        $this->actingAs($superadmin)
            ->post(route('admin.users.bulk-delete'), ['user_ids' => [$target->id]])
            ->assertRedirect(route('admin.users'));

        $this->assertSoftDeleted('users', ['id' => $target->id]);

        $this->actingAs($superadmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertDontSee($target->username);

        $this->actingAs($superadmin)
            ->get(route('admin.users', ['deleted' => 1]))
            ->assertOk()
            ->assertSee($target->username)
            ->assertSee('Восстановить');

        $this->actingAs($superadmin)
            ->post(route('admin.users.bulk-restore'), ['user_ids' => [$target->id]])
            ->assertRedirect(route('admin.users', ['deleted' => 1]));

        $this->assertNotSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_superadmin_cannot_change_or_delete_own_account(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);

        $this->actingAs($superadmin)
            ->post(route('admin.users.status.update', $superadmin), ['status' => UserStatusEnum::BLOCKED->value])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('error');

        $this->actingAs($superadmin)
            ->post(route('admin.users.bulk-delete'), ['user_ids' => [$superadmin->id]])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('error');

        $this->assertNull($superadmin->fresh()->deleted_at);
        $this->assertSame(UserStatusEnum::CONFIRMED, $superadmin->fresh()->status);
    }

    private function user(
        UserSystemRoleEnum $role,
        UserStatusEnum $status = UserStatusEnum::CONFIRMED,
    ): User {
        return User::factory()->create([
            'status' => $status,
            'system_role' => $role,
        ]);
    }
}
