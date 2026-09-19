<?php

namespace Tests\Feature\Admin;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_edit_basic_user_details_and_assign_temporary_password(): void
    {
        config()->set('audit.ignore_console', false);
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);

        $this->actingAs($superadmin)
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertSee($target->username);

        $this->actingAs($superadmin)
            ->put(route('admin.users.update', $target), [
                'first_name' => 'Иван',
                'last_name' => 'Иванов',
                'middle_name' => 'Иванович',
                'birth_date' => '2000-05-10',
                'password' => 'NewStrong1!',
                'password_confirmation' => 'NewStrong1!',
            ])
            ->assertRedirect(route('admin.users.edit', $target))
            ->assertSessionHas('success');

        $target->refresh()->load('profile');
        $this->assertSame('Иван', $target->profile->first_name);
        $this->assertSame('Иванов', $target->profile->last_name);
        $this->assertSame('Иванович', $target->profile->middle_name);
        $this->assertSame('2000-05-10', $target->profile->birth_date->toDateString());
        $this->assertTrue($target->is_temporary_password);
        $this->assertTrue(Hash::check('NewStrong1!', $target->password));

        $profileAudit = AuditLog::query()
            ->where('auditable_type', $target->profile::class)
            ->where('auditable_id', $target->profile->id)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('Иван', $profileAudit->new_values['first_name']);

        $userAudit = AuditLog::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $target->id)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertArrayNotHasKey('password', $userAudit->new_values);
    }

    public function test_admin_cannot_open_or_update_basic_user_details(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $target))
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $target), ['first_name' => 'Недоступно'])
            ->assertForbidden();
    }

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

    public function test_system_role_management_permission_defaults_to_superadmin_only(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $checker = app(UserOperationalPermissionChecker::class);

        $this->assertTrue($checker->allows($superadmin, UserOperationalPermissionEnum::MANAGE_SYSTEM_ROLES));
        $this->assertFalse($checker->allows($admin, UserOperationalPermissionEnum::MANAGE_SYSTEM_ROLES));
    }

    public function test_superadmin_can_assign_and_reset_lower_system_roles(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);

        $this->actingAs($superadmin)
            ->post(route('admin.users.system-role.update', $target), [
                'role' => UserSystemRoleEnum::MODERATOR->value,
            ])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('success');

        $this->assertSame(UserSystemRoleEnum::MODERATOR, $target->refresh()->system_role);

        $this->actingAs($superadmin)
            ->post(route('admin.users.system-role.update', $target), [
                'role' => UserSystemRoleEnum::USER->value,
            ])
            ->assertRedirect(route('admin.users'));

        $this->assertSame(UserSystemRoleEnum::USER, $target->refresh()->system_role);
    }

    public function test_admin_requires_permission_and_can_only_assign_roles_below_own_rank(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $peer = $this->user(UserSystemRoleEnum::ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.users.system-role.update', $target), [
                'role' => UserSystemRoleEnum::MODERATOR->value,
            ])
            ->assertForbidden();

        UserOperationalPermission::query()->create([
            'user_id' => $admin->id,
            'permission' => UserOperationalPermissionEnum::MANAGE_SYSTEM_ROLES,
            'is_allowed' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.users.system-role.update', $target), [
                'role' => UserSystemRoleEnum::MODERATOR->value,
            ])
            ->assertRedirect(route('admin.users'));

        $this->assertSame(UserSystemRoleEnum::MODERATOR, $target->refresh()->system_role);

        $this->actingAs($admin)
            ->post(route('admin.users.system-role.update', $target), [
                'role' => UserSystemRoleEnum::ADMIN->value,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.users.system-role.update', $peer), [
                'role' => UserSystemRoleEnum::USER->value,
            ])
            ->assertForbidden();
    }

    public function test_superadmin_cannot_assign_superadmin_role(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::ADMIN);

        $this->actingAs($superadmin)
            ->post(route('admin.users.system-role.update', $target), [
                'role' => UserSystemRoleEnum::SUPERADMIN->value,
            ])
            ->assertForbidden();

        $this->assertSame(UserSystemRoleEnum::ADMIN, $target->refresh()->system_role);
    }

    public function test_operational_permissions_are_allowed_by_default_and_admin_can_disable_them(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $checker = app(UserOperationalPermissionChecker::class);

        $this->assertTrue($checker->allows($target, UserOperationalPermissionEnum::CREATE_COORDINATION));
        $this->assertDatabaseMissing('user_operational_permissions', ['user_id' => $target->id]);

        $this->actingAs($admin)
            ->post(route('admin.users.operational-permissions.update', $target), [
                'permissions' => [],
            ])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('user_operational_permissions', [
            'user_id' => $target->id,
            'permission' => UserOperationalPermissionEnum::CREATE_COORDINATION->value,
            'is_allowed' => false,
        ]);
        $this->assertFalse($checker->allows($target, UserOperationalPermissionEnum::CREATE_COORDINATION));
    }

    public function test_operational_permission_management_respects_role_hierarchy(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $peer = $this->user(UserSystemRoleEnum::ADMIN);
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);

        $this->actingAs($admin)
            ->post(route('admin.users.operational-permissions.update', $peer), [
                'permissions' => [],
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.users.operational-permissions.update', $admin), [
                'permissions' => [],
            ])
            ->assertForbidden();

        $this->actingAs($superadmin)
            ->post(route('admin.users.operational-permissions.update', $admin), [
                'permissions' => [],
            ])
            ->assertRedirect(route('admin.users'));
    }

    public function test_operational_permission_changes_are_audited(): void
    {
        config()->set('audit.ignore_console', false);

        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);

        $this->actingAs($admin)
            ->post(route('admin.users.operational-permissions.update', $target), [
                'permissions' => [],
            ])
            ->assertRedirect(route('admin.users'));

        $permission = UserOperationalPermission::query()
            ->whereBelongsTo($target)
            ->firstOrFail();

        $audit = AuditLog::query()
            ->where('auditable_type', UserOperationalPermission::class)
            ->where('auditable_id', $permission->id)
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertFalse($audit->new_values['is_allowed']);
        $this->assertNotNull($audit->actor_id);
    }

    public function test_admin_user_list_displays_operational_permissions(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);

        $this->actingAs($superadmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('Операционные права')
            ->assertSee(UserOperationalPermissionEnum::CREATE_COORDINATION->label())
            ->assertSee(UserOperationalPermissionEnum::MANAGE_SYSTEM_ROLES->label())
            ->assertSee(route('admin.users.system-role.update', $target))
            ->assertSee(route('admin.users.operational-permissions.update', $target));
    }

    public function test_superadmin_user_editor_exposes_tabs_and_soft_delete_action(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);

        $this->actingAs($superadmin)
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertSee('Общая информация')
            ->assertSee('Профиль')
            ->assertSee('Роли')
            ->assertSee('История')
            ->assertSee(route('admin.users.bulk-delete'), false)
            ->assertSee('вы уверены что хотите удалить аккаунт')
            ->assertSee('Удалить');
    }

    public function test_only_superadmin_sees_user_login_as_edit_link(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);

        $this->actingAs($admin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertDontSee(route('admin.users.edit', $target), false);

        $this->actingAs($superadmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee(route('admin.users.edit', $target), false)
            ->assertSee('admin-table admin-table--users', false);
    }

    public function test_superadmin_user_editor_shows_registration_metadata_from_canonical_identity(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
            'registration_channel' => UserRegistrationChannelEnum::SITE_FULL_REGISTRATION,
        ]);
        $alias = User::factory()->create([
            'status' => UserStatusEnum::UNCONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
            'registration_channel' => UserRegistrationChannelEnum::TELEGRAM_MINI_APP,
        ]);
        $alias->forceFill(['canonical_user_id' => $target->id])->save();

        TelegramAccount::query()->create([
            'user_id' => $alias->id,
            'telegram_user_id' => 777000,
            'username' => 'court_player',
            'first_name' => 'Dmitry',
            'last_name' => 'Hooper',
            'language_code' => 'ru',
            'last_auth_at' => now(),
        ]);

        $this->actingAs($superadmin)
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertSee('Мета-информация регистрации')
            ->assertSee('Полная регистрация')
            ->assertSee('Telegram Mini App')
            ->assertSee('TG ID 777000')
            ->assertSee('@court_player')
            ->assertSee('Dmitry Hooper');
    }

    public function test_superadmin_can_edit_operational_permissions_from_roles_tab_and_returns_to_it(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);

        $this->actingAs($superadmin)
            ->get(route('admin.users.edit', ['user' => $target, 'tab' => 'roles']))
            ->assertOk()
            ->assertSee('Сохранить права')
            ->assertSee(route('admin.users.operational-permissions.update', $target), false)
            ->assertSee(UserOperationalPermissionEnum::CREATE_TEAM->value);

        $this->actingAs($superadmin)
            ->post(route('admin.users.operational-permissions.update', $target), [
                'return_to' => 'user-edit',
                'permissions' => [UserOperationalPermissionEnum::CREATE_TEAM->value],
            ])
            ->assertRedirect(route('admin.users.edit', ['user' => $target, 'tab' => 'roles']))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('user_operational_permissions', [
            'user_id' => $target->id,
            'permission' => UserOperationalPermissionEnum::CREATE_TEAM->value,
            'is_allowed' => true,
        ]);
        $this->assertDatabaseHas('user_operational_permissions', [
            'user_id' => $target->id,
            'permission' => UserOperationalPermissionEnum::CREATE_COORDINATION->value,
            'is_allowed' => false,
        ]);
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
