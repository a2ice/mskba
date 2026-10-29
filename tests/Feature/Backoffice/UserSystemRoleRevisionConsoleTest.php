<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Identity\Application\UseCases\AdminChangeUserSystemRoleWithAuditHandler;
use App\Modules\Identity\Application\UseCases\AdminRevertUserSystemRoleHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UserSystemRoleRevisionConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $role): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
    }

    public function test_superadmin_can_audit_role_promotion_and_revert_without_restoring_grants(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->actingAs($super);
        $editor = app(AdminChangeUserSystemRoleWithAuditHandler::class);
        $before = $editor->snapshot($target);

        $revision = $editor->handle(
            $super, $target, UserSystemRoleEnum::MANAGER,
            'Назначение менеджера с подтверждением',
        );
        $this->assertNotNull($revision);
        $this->assertSame('admin_system_role_changed', $revision->event);
        $this->assertSame(UserSystemRoleEnum::MANAGER, $target->fresh()->system_role);

        $revert = app(AdminRevertUserSystemRoleHandler::class)->handle(
            $super, $target, $revision->id,
            'Отмена временного назначения менеджером',
        );
        $this->assertSame('admin_system_role_reverted', $revert->event);
        $this->assertSame($before, $editor->snapshot($target->fresh()));
        $this->assertNotNull($revision->fresh());
    }

    public function test_admin_without_personal_edit_cannot_change_system_role_through_wrapper(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->actingAs($admin);
        $this->expectException(AuthorizationException::class);
        app(AdminChangeUserSystemRoleWithAuditHandler::class)->handle(
            $admin, $target, UserSystemRoleEnum::MANAGER,
            'Прямая попытка системного назначения без права',
        );
    }

    public function test_rollback_never_resurrects_explicit_privileges_revoked_on_demotion(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $manager = $this->user(UserSystemRoleEnum::MANAGER);
        $this->actingAs($super);
        UserOperationalPermission::query()->create([
            'user_id' => $manager->id,
            'permission' => Permission::OPERATOR_PRESENCE_VIEW,
            'is_allowed' => true,
        ]);

        $revision = app(AdminChangeUserSystemRoleWithAuditHandler::class)->handle(
            $super, $manager, UserSystemRoleEnum::USER,
            'Временное понижение роли с отзывом привилегий',
        );
        $this->assertSame(UserSystemRoleEnum::USER, $manager->fresh()->system_role);

        $this->expectException(ValidationException::class);
        try {
            app(AdminRevertUserSystemRoleHandler::class)->handle(
                $super, $manager, $revision->id,
                'Попытка вернуть ранее отозванные повышенные права',
            );
        } finally {
            $this->assertSame(UserSystemRoleEnum::USER, $manager->fresh()->system_role);
            $this->assertFalse((bool) UserOperationalPermission::query()->where('user_id', $manager->id)
                ->where('permission', Permission::OPERATOR_PRESENCE_VIEW->value)->value('is_allowed'));
        }
    }
}
