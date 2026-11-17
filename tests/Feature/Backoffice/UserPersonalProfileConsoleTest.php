<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\UseCases\AdminEditPersonalProfileHandler;
use App\Modules\Identity\Application\UseCases\AdminRevertPersonalProfileHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UserPersonalProfileConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $role): User
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
        $user->createProfile([]);
        return $user;
    }

    public function test_separate_personal_profile_permission_controls_editor_even_for_admin(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);

        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertTrue($checker->allows($super, Permission::EDIT_USER_PERSONAL_DATA));
        $this->assertFalse($checker->allows($admin, Permission::EDIT_USER_PERSONAL_DATA));
        $this->assertTrue($checker->allows($admin, Permission::EDIT_OWN_PROFILE));
        $this->assertTrue($checker->allows($admin, Permission::REVIEW_PROFILE_CHANGES));

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->assertTrue(BackofficeUserResource::canViewAny());
        $this->assertFalse(BackofficeUserResource::canEdit($target));
        $this->expectException(AuthorizationException::class);
        app(AdminEditPersonalProfileHandler::class)->handle(
            $admin, $target, ['first_name' => 'Имя'], 'Отклонённое изменение',
        );
    }

    public function test_superadmin_can_change_confirmed_data_with_audit_and_revert_without_destroying_history(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->actingAs($super);
        $revision = app(AdminEditPersonalProfileHandler::class)->handle(
            $super, $target, ['first_name' => 'Изменено', 'gender' => 'female'],
            'Обращение пользователя с подтверждением',
        );
        $this->assertSame('admin_personal_profile_changed', $revision->event);
        $this->assertSame('Изменено', $target->fresh()->profile->first_name);
        $this->assertSame('Обращение пользователя с подтверждением', $revision->metadata['reason']);

        $reverted = app(AdminRevertPersonalProfileHandler::class)->handle(
            $super, $target, $revision->id, 'Откат ошибочно внесённого изменения',
        );
        $this->assertSame('admin_personal_profile_reverted', $reverted->event);
        $this->assertNull($target->fresh()->profile->first_name);
        $this->assertNull($target->fresh()->profile->gender);
        $this->assertNotNull($revision->fresh());
    }

    public function test_revert_fails_closed_if_fields_have_changed_after_revision(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->actingAs($super);
        $revision = app(AdminEditPersonalProfileHandler::class)->handle(
            $super, $target, ['first_name' => 'Первое'], 'Проверка первого редактирования',
        );
        $target->fresh()->profile->update(['first_name' => 'Второе']);
        $this->expectException(ValidationException::class);
        app(AdminRevertPersonalProfileHandler::class)->handle(
            $super, $target, $revision->id, 'Проверка запрета конфликтного отката',
        );
    }
}
