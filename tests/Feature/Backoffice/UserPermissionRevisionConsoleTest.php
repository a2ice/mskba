<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\UseCases\AdminRevertUserPermissionSnapshotHandler;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserPermissionSnapshotHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UserPermissionRevisionConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $role): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
    }

    public function test_justified_permission_revision_and_compensating_rollback(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $manager = $this->user(UserSystemRoleEnum::MANAGER);
        $this->actingAs($super);
        $editor = app(AdminUpdateUserPermissionSnapshotHandler::class);
        $before = $editor->snapshot($manager);

        $revision = $editor->handle($super, $manager, [], [
            Permission::HANDLE_SUPPORT_TICKETS->value,
        ], [], 'Проверка выдачи полномочий поддержки');

        $this->assertNotNull($revision);
        $this->assertSame('admin_user_permissions_changed', $revision->event);
        $this->assertSame('Проверка выдачи полномочий поддержки', $revision->metadata['reason']);
        $this->assertTrue(app(UserOperationalPermissionChecker::class)->allows($manager, Permission::HANDLE_SUPPORT_TICKETS));

        $reverted = app(AdminRevertUserPermissionSnapshotHandler::class)->handle(
            $super, $manager, $revision->id, 'Отзыв временно выданных прав поддержки',
        );
        $this->assertSame('admin_user_permissions_reverted', $reverted->event);
        $this->assertSame($before, $editor->snapshot($manager->fresh()));
        $this->assertNotNull($revision->fresh());
    }

    public function test_admin_without_edit_permission_cannot_save_any_global_grants(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::MANAGER);
        $this->actingAs($admin);
        $this->expectException(AuthorizationException::class);
        app(AdminUpdateUserPermissionSnapshotHandler::class)->handle(
            $admin, $target, [], [], [], 'Попытка прямого изменения разрешений',
        );
    }

    public function test_revision_requires_reason_even_from_superadmin(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::MANAGER);
        $this->actingAs($super);
        $this->expectException(ValidationException::class);
        app(AdminUpdateUserPermissionSnapshotHandler::class)->handle(
            $super, $target, [], [], [], '',
        );
    }

    public function test_rollback_rejects_other_permission_change_after_revision(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::MANAGER);
        $this->actingAs($super);
        $revision = app(AdminUpdateUserPermissionSnapshotHandler::class)->handle(
            $super, $target, [], [Permission::HANDLE_SUPPORT_TICKETS->value], [],
            'Первое изменение операционных прав менеджера',
        );
        app(BackofficePermissionManager::class)->sync($super, $target, [
            Permission::BACKOFFICE_VENUE_MANAGE->value,
        ]);

        $this->expectException(ValidationException::class);
        app(AdminRevertUserPermissionSnapshotHandler::class)->handle(
            $super, $target, $revision->id, 'Запрет отката после других изменённых полномочий',
        );
    }
}
