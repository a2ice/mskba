<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminOperationalPermissionDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_superadmin_have_all_operational_permissions_by_default(): void
    {
        $checker = app(UserOperationalPermissionChecker::class);

        foreach ([UserSystemRoleEnum::ADMIN, UserSystemRoleEnum::SUPERADMIN] as $role) {
            $user = User::factory()->create([
                'status' => UserStatusEnum::CONFIRMED,
                'system_role' => $role,
            ]);

            foreach (UserOperationalPermissionEnum::cases() as $permission) {
                $this->assertTrue(
                    $checker->allows($user, $permission),
                    "{$role->value} should allow {$permission->value} by default.",
                );
            }
        }
    }

    public function test_regular_user_still_uses_permission_specific_defaults(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        $checker = app(UserOperationalPermissionChecker::class);

        $this->assertTrue($checker->allows($user, UserOperationalPermissionEnum::CREATE_COORDINATION));
        $this->assertTrue($checker->allows($user, UserOperationalPermissionEnum::CREATE_TEAM));
        $this->assertFalse($checker->allows($user, UserOperationalPermissionEnum::CREATE_EVENT));
        $this->assertFalse($checker->allows($user, UserOperationalPermissionEnum::CREATE_TOURNAMENT));
    }

    public function test_explicit_snapshot_overrides_admin_default(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);

        UserOperationalPermission::query()->create([
            'user_id' => $admin->id,
            'permission' => UserOperationalPermissionEnum::CREATE_TOURNAMENT,
            'is_allowed' => false,
        ]);

        $this->assertFalse(
            app(UserOperationalPermissionChecker::class)
                ->allows($admin, UserOperationalPermissionEnum::CREATE_TOURNAMENT),
        );
    }

    public function test_blocked_admin_does_not_bypass_operational_restrictions(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::BLOCKED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);

        foreach (UserOperationalPermissionEnum::cases() as $permission) {
            $this->assertFalse(
                app(UserOperationalPermissionChecker::class)->allows($admin, $permission),
            );
        }
    }
}
