<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Identity\Application\Services\StaffPermissionProfiles;
use App\Modules\Identity\Application\Services\StaffSpecializationManager;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserSystemRoleHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\ManagerSpecialization;
use App\Modules\Identity\Domain\Models\SystemRolePermissionProfile;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ManagerSpecializationsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(Role $role): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
    }

    public function test_manager_has_filament_entry_but_no_function_specific_rights_without_specialization(): void
    {
        $manager = $this->actor(Role::MANAGER);
        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertTrue($checker->allows($manager, Permission::BACKOFFICE_ACCESS));
        $this->assertFalse($checker->allows($manager, Permission::CONTENT_MANAGE));
        $this->assertFalse($checker->allows($manager, Permission::OPERATOR_MARKETING_SEND));
    }

    public function test_explicit_denial_overrides_role_and_specialization_defaults(): void
    {
        $super = $this->actor(Role::SUPERADMIN);
        $manager = $this->actor(Role::MANAGER);
        $editor = ManagerSpecialization::query()->where('code', 'editor')->firstOrFail();
        app(StaffSpecializationManager::class)->sync($super, $manager, [$editor->id]);
        foreach ([Permission::CONTENT_MANAGE, Permission::BACKOFFICE_ACCESS] as $permission) {
            UserOperationalPermission::query()->create([
                'user_id' => $manager->id, 'permission' => $permission, 'is_allowed' => false,
            ]);
        }
        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertFalse($checker->allows($manager, Permission::CONTENT_MANAGE));
        $this->assertFalse($checker->allows($manager, Permission::BACKOFFICE_ACCESS));
    }

    public function test_multiple_specializations_union_rights(): void
    {
        $super = $this->actor(Role::SUPERADMIN);
        $manager = $this->actor(Role::MANAGER);
        $editor = ManagerSpecialization::query()->where('code', 'editor')->firstOrFail();
        $advertiser = ManagerSpecialization::query()->where('code', 'advertiser')->firstOrFail();
        app(StaffSpecializationManager::class)->sync($super, $manager, [$editor->id, $advertiser->id]);
        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertTrue($checker->allows($manager, Permission::CONTENT_MANAGE));
        $this->assertTrue($checker->allows($manager, Permission::OPERATOR_CONVERSATION_HANDLE));
        $this->assertTrue($checker->allows($manager, Permission::OPERATOR_MARKETING_SEND));
        $this->assertFalse($checker->allows($manager, Permission::MANAGE_SYSTEM_ROLES));
        $this->assertCount(2, $manager->managerSpecializations()->get());
    }

    public function test_moderator_keeps_legacy_content_management_gate_without_builder_code_rights(): void
    {
        $super = $this->actor(Role::SUPERADMIN);
        $manager = $this->actor(Role::MANAGER);
        $moderator = ManagerSpecialization::query()->where('code', 'moderator')->firstOrFail();
        app(StaffSpecializationManager::class)->sync($super, $manager, [$moderator->id]);

        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertTrue($checker->allows($manager, Permission::CONTENT_MANAGE));
        $this->assertFalse($checker->allows($manager, Permission::BACKOFFICE_BUILDER_EDIT));
        $this->assertFalse($checker->allows($manager, Permission::BACKOFFICE_BUILDER_PUBLISH));
    }

    public function test_admin_cannot_revoke_unknown_backoffice_right_from_subordinate(): void
    {
        $super = $this->actor(Role::SUPERADMIN);
        $admin = $this->actor(Role::ADMIN);
        $manager = $this->actor(Role::MANAGER);
        app(\App\Modules\Identity\Application\Services\BackofficePermissionManager::class)
            ->sync($super, $manager, [Permission::BACKOFFICE_STYLE_VIEW->value]);
        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertTrue($checker->allows($manager, Permission::BACKOFFICE_STYLE_VIEW));

        app(\App\Modules\Identity\Application\Services\BackofficePermissionManager::class)
            ->sync($admin, $manager, []);
        $this->assertTrue($checker->allows($manager, Permission::BACKOFFICE_STYLE_VIEW));
    }

    public function test_admin_cannot_assign_specialization_exceeding_own_permissions(): void
    {
        $admin = $this->actor(Role::ADMIN);
        $manager = $this->actor(Role::MANAGER);
        $advertiser = ManagerSpecialization::query()->where('code', 'advertiser')->firstOrFail();
        $this->expectException(AuthorizationException::class);
        app(StaffSpecializationManager::class)->sync($admin, $manager, [$advertiser->id]);
    }

    public function test_forbidden_specialization_permissions_rejected_even_when_superadmin_assigns(): void
    {
        $super = $this->actor(Role::SUPERADMIN);
        $manager = $this->actor(Role::MANAGER);
        $dangerous = ManagerSpecialization::query()->create([
            'code' => 'unsafe', 'name' => 'Запрещённая',
            'permissions' => [Permission::MANAGE_SYSTEM_ROLES->value], 'is_system' => false,
        ]);
        $this->expectException(AuthorizationException::class);
        app(StaffSpecializationManager::class)->sync($super, $manager, [$dangerous->id]);
    }

    public function test_user_cannot_access_filament_even_with_legacy_positive_grant(): void
    {
        $user = $this->actor(Role::USER);
        UserOperationalPermission::query()->create([
            'user_id' => $user->id, 'permission' => Permission::BACKOFFICE_ACCESS, 'is_allowed' => true,
        ]);
        $this->assertFalse(app(UserOperationalPermissionChecker::class)->allows($user, Permission::BACKOFFICE_ACCESS));
    }

    public function test_downgrade_clears_specializations(): void
    {
        $super = $this->actor(Role::SUPERADMIN);
        $manager = $this->actor(Role::MANAGER);
        $editor = ManagerSpecialization::query()->where('code', 'editor')->firstOrFail();
        app(StaffSpecializationManager::class)->sync($super, $manager, [$editor->id]);
        app(AdminUpdateUserSystemRoleHandler::class)->handle($super, $manager->id, Role::USER);
        $this->assertSame(Role::USER, $manager->fresh()->system_role);
        $this->assertSame(0, $manager->managerSpecializations()->count());
        $this->assertFalse(app(UserOperationalPermissionChecker::class)->allows($manager->fresh(), Permission::CONTENT_MANAGE));
    }

    public function test_downgrade_disables_personal_operator_grant_and_does_not_restore_it_on_repromotion(): void
    {
        $super = $this->actor(Role::SUPERADMIN);
        $manager = $this->actor(Role::MANAGER);
        UserOperationalPermission::query()->create([
            'user_id' => $manager->id,
            'permission' => Permission::OPERATOR_CONVERSATION_HANDLE, 'is_allowed' => true,
        ]);
        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertTrue($checker->allows($manager, Permission::OPERATOR_CONVERSATION_HANDLE));

        $change = app(AdminUpdateUserSystemRoleHandler::class);
        $change->handle($super, $manager->id, Role::USER);
        $this->assertFalse($checker->allows($manager->fresh(), Permission::OPERATOR_CONVERSATION_HANDLE));
        $change->handle($super, $manager->id, Role::MANAGER);
        $this->assertFalse($checker->allows($manager->fresh(), Permission::OPERATOR_CONVERSATION_HANDLE));
    }

    public function test_old_user_admin_endpoint_cannot_grant_outside_admin_effective_rights(): void
    {
        $admin = $this->actor(Role::ADMIN);
        $user = $this->actor(Role::USER);
        $profile = SystemRolePermissionProfile::query()->findOrFail(Role::ADMIN->value);
        $profile->update(['permissions' => array_values(array_diff(
            $profile->permissions, [Permission::CREATE_EVENT->value]
        ))]);

        $this->expectException(AuthorizationException::class);
        app(\App\Modules\Identity\Application\UseCases\AdminUpdateUserOperationalPermissionsHandler::class)
            ->handle($admin, $user->id, [Permission::CREATE_EVENT]);
    }

    public function test_old_user_admin_endpoint_cannot_revoke_right_outside_admin_scope(): void
    {
        $admin = $this->actor(Role::ADMIN);
        $user = $this->actor(Role::USER);
        UserOperationalPermission::query()->create([
            'user_id' => $user->id,
            'permission' => Permission::CREATE_EVENT, 'is_allowed' => true,
        ]);
        $profile = SystemRolePermissionProfile::query()->findOrFail(Role::ADMIN->value);
        $profile->update(['permissions' => array_values(array_diff(
            $profile->permissions, [Permission::CREATE_EVENT->value]
        ))]);

        app(\App\Modules\Identity\Application\UseCases\AdminUpdateUserOperationalPermissionsHandler::class)
            ->handle($admin, $user->id, []);
        $this->assertTrue(app(UserOperationalPermissionChecker::class)->allows($user, Permission::CREATE_EVENT));
    }

    public function test_system_role_profile_changes_baseline_for_existing_manager(): void
    {
        $manager = $this->actor(Role::MANAGER);
        $profile = SystemRolePermissionProfile::query()->findOrFail(Role::MANAGER->value);
        $profile->update(['permissions' => array_merge($profile->permissions, [Permission::CONTENT_MANAGE->value])]);
        $this->assertTrue(app(UserOperationalPermissionChecker::class)->allows($manager, Permission::CONTENT_MANAGE));
        $this->assertTrue(app(StaffPermissionProfiles::class)->inherited($manager, Permission::CONTENT_MANAGE));
    }
}