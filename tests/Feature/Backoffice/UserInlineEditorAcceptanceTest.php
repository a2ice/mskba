<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\EditBackofficeUser;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\UseCases\AdminAssignParticipationRoleHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class UserInlineEditorAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $role): User
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
        $user->createProfile([]);
        return $user;
    }

    public function test_superadmin_can_override_inherited_creation_right_and_restore_it(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $global = app(GlobalOperationalPermissionManager::class);
        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertTrue($checker->allows($target, Permission::CREATE_TEAM));

        $global->sync($super, $target, [], [], true);
        $this->assertFalse($checker->allows($target->fresh(), Permission::CREATE_TEAM));
        $this->assertDatabaseHas('user_operational_permissions', [
            'user_id' => $target->id,
            'permission' => Permission::CREATE_TEAM->value,
            'is_allowed' => false,
        ]);
        $global->sync($super, $target, [Permission::CREATE_TEAM->value], [], true);
        $this->assertTrue($checker->allows($target->fresh(), Permission::CREATE_TEAM));
    }

    public function test_admin_cannot_bypass_system_role_ceiling_even_as_superadmin(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(GlobalOperationalPermissionManager::class)->sync($super, $target, [], [
            Permission::HANDLE_SUPPORT_TICKETS->value,
        ]);
    }

    public function test_assignment_rejects_duplicates_and_preserves_disabled_role(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->actingAs($super);
        $handler = app(AdminAssignParticipationRoleHandler::class);
        $audit = $handler->handle($super, $target, UserParticipationRoleEnum::PLAYER->value,
            'Назначение игрока администратором');
        $this->assertSame('admin_participation_role_added', $audit->event);
        $id = (int) $audit->metadata['participation_role_id'];
        $this->assertNotNull($target->playerProfile);
        $target->participationRoles(false)->findOrFail($id)->update(['status' => UserParticipationRoleStatusEnum::INACTIVE]);
        $this->expectException(ValidationException::class);
        $handler->handle($super, $target, UserParticipationRoleEnum::PLAYER->value,
            'Повторная выдача уже назначенной роли');
    }

    public function test_editor_has_single_participation_role_tab_and_editable_profile_fields(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->assertSee('Роль')
            ->assertSee('Сохранить профиль')
            ->assertSee('Добавить роль')
            ->assertSee('Имя');
    }

    public function test_inline_personal_field_save_requires_audited_reason(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['profile_first_name' => 'Иван'])
            ->callAction('savePersonalInline', data: ['reason' => 'Подтверждено по обращению владельца'])
            ->assertHasNoFormErrors();
        $this->assertSame('Иван', $target->profile->fresh()->first_name);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $target->id, 'event' => 'admin_personal_profile_changed',
        ]);
    }
    public function test_participation_role_is_edited_inline_after_row_selection(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $audit = app(AdminAssignParticipationRoleHandler::class)->handle(
            $super, $target, UserParticipationRoleEnum::COACH->value,
            'Добавляем тренера перед редакторской проверкой',
        );
        $roleId = (int) $audit->metadata['participation_role_id'];

        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->call('selectParticipationRole', $roleId)
            ->assertSet('selectedParticipationRoleId', $roleId)
            ->set('roleComment', 'Тренирует молодёжную команду')
            ->callAction('saveParticipationRoleInline', data: [
                'reason' => 'Подтверждение обновления анкеты тренера',
            ])
            ->assertHasNoFormErrors();

        $role = $target->participationRoles(false)->findOrFail($roleId);
        $this->assertSame('Тренирует молодёжную команду', $role->comment);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $target->id,
            'event' => 'admin_participation_role_changed',
        ]);
    }

    public function test_system_role_is_saved_from_inline_selector_with_reason(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['requested_system_role' => UserSystemRoleEnum::MANAGER->value])
            ->callAction('saveSystemRoleInline', data: [
                'reason' => 'Назначение менеджера после подтверждённой заявки',
            ])->assertHasNoFormErrors();

        $this->assertSame(UserSystemRoleEnum::MANAGER, $target->fresh()->system_role);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $target->id, 'event' => 'admin_system_role_changed',
        ]);
    }

    public function test_manager_specializations_are_saved_from_inline_fields(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::MANAGER);
        $specialization = \App\Modules\Identity\Domain\Models\ManagerSpecialization::query()
            ->where('code', 'editor')->firstOrFail();
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'manager_staff_title' => 'Координатор площадок',
                'manager_specialization_ids' => [(string) $specialization->id],
            ])
            ->callAction('saveSpecializationsInline', data: [
                'reason' => 'Назначение функциональной специализации менеджера',
            ])->assertHasNoFormErrors();

        $this->assertSame('Координатор площадок', $target->fresh()->staff_title);
        $this->assertTrue($target->managerSpecializations()->whereKey($specialization->id)->exists());
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $target->id, 'event' => 'admin_staff_specializations_changed',
        ]);
    }

}
