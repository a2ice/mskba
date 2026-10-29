<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Identity\Application\UseCases\AdminRevertStaffSpecializationsHandler;
use App\Modules\Identity\Application\UseCases\AdminUpdateStaffSpecializationsWithAuditHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\ManagerSpecialization;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UserStaffSpecializationsConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $role): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
    }

    private function specialization(): ManagerSpecialization
    {
        return ManagerSpecialization::query()->create([
            'code' => 'event-monitor-062',
            'name' => 'Мониторинг активности',
            'permissions' => [Permission::OPERATOR_PRESENCE_VIEW->value],
            'is_system' => false,
        ]);
    }

    public function test_superadmin_can_assign_manager_specialization_and_restore_with_audit(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $manager = $this->user(UserSystemRoleEnum::MANAGER);
        $specialization = $this->specialization();
        $this->actingAs($super);
        $editor = app(AdminUpdateStaffSpecializationsWithAuditHandler::class);
        $before = $editor->snapshot($manager);

        $revision = $editor->handle(
            $super, $manager, [$specialization->id], 'Куратор мероприятий',
            'Временное назначение ответственного менеджера',
        );
        $this->assertNotNull($revision);
        $this->assertSame('admin_staff_specializations_changed', $revision->event);
        $this->assertSame([$specialization->id], $editor->snapshot($manager->fresh())['specialization_ids']);

        $revert = app(AdminRevertStaffSpecializationsHandler::class)->handle(
            $super, $manager, $revision->id, 'Отмена временного назначения менеджера',
        );
        $this->assertSame('admin_staff_specializations_reverted', $revert->event);
        $this->assertSame($before, $editor->snapshot($manager->fresh()));
        $this->assertNotNull($revision->fresh());
    }

    public function test_admin_without_personal_edit_right_is_denied_staff_assignment(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::MANAGER);
        $this->actingAs($admin);

        $this->expectException(AuthorizationException::class);
        app(AdminUpdateStaffSpecializationsWithAuditHandler::class)->handle(
            $admin, $target, [], 'Тестовая роль',
            'Попытка назначить специализацию без разрешения',
        );
    }

    public function test_conflict_blocks_revert_if_staff_title_was_modified_after_revision(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $manager = $this->user(UserSystemRoleEnum::MANAGER);
        $this->actingAs($super);
        $revision = app(AdminUpdateStaffSpecializationsWithAuditHandler::class)->handle(
            $super, $manager, [], 'Координатор',
            'Начальное изменение должности менеджера',
        );
        $manager->fresh()->forceFill(['staff_title' => 'Старший координатор'])->save();

        $this->expectException(ValidationException::class);
        app(AdminRevertStaffSpecializationsHandler::class)->handle(
            $super, $manager, $revision->id, 'Проверка отката после другой корректировки',
        );
    }
}
