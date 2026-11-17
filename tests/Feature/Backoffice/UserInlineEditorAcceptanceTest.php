<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\EditBackofficeUser;
use App\Filament\Backoffice\Resources\PageBuilder\BuilderComponentResource;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\StaffPermissionProfiles;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\UseCases\AdminAssignParticipationRoleHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
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

    public function test_superadmin_can_assign_system_role_and_grant_page_builder_access_to_unconfirmed_account(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $target->update(['status' => UserStatusEnum::UNCONFIRMED]);
        $this->actingAs($super);
        $panel = Filament::getPanel('backoffice');
        Filament::setCurrentPanel($panel);

        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['requested_system_role' => UserSystemRoleEnum::SYSTEM->value])
            ->callAction('saveSystemRoleInline', data: ['reason' => 'Назначение роли для работы в конструкторе'])
            ->assertHasNoFormErrors();

        $target->refresh();
        $this->assertSame(UserSystemRoleEnum::SYSTEM, $target->system_role);
        $this->assertFalse($target->canAccessPanel($panel));
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $target->id, 'event' => 'admin_system_role_changed',
        ]);

        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['backoffice_permissions' => [
                Permission::BACKOFFICE_ACCESS->value,
                Permission::BACKOFFICE_BUILDER_VIEW->value,
            ]])
            ->callAction('savePermissionsInline', data: ['reason' => 'Доступ только к конструктору страниц'])
            ->assertHasNoFormErrors();

        $target->refresh();
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $target->status);
        $this->assertTrue($target->canAccessPanel($panel));
        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertTrue($checker->allows($target, Permission::BACKOFFICE_BUILDER_VIEW));
        $this->assertFalse($checker->allows($target, Permission::BACKOFFICE_BUILDER_EDIT));
        $this->assertFalse($checker->allows($target, Permission::BACKOFFICE_VENUE_MANAGE));
        $this->withoutVite();
        $this->actingAs($target)->get(BuilderComponentResource::getUrl('index'))->assertOk();

        $target->update(['status' => UserStatusEnum::BLOCKED]);
        $this->assertFalse($target->fresh()->canAccessPanel($panel));
    }

    public function test_superadmin_can_assign_admin_permissions_to_system_account(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::SYSTEM);
        $target->update(['status' => UserStatusEnum::UNCONFIRMED]);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $profiles = app(StaffPermissionProfiles::class);
        foreach (Permission::cases() as $permission) {
            $this->assertSame(
                $profiles->allowedForRole(UserSystemRoleEnum::ADMIN, $permission),
                $profiles->allowedForRole(UserSystemRoleEnum::SYSTEM, $permission),
                $permission->value,
            );
        }
        $this->assertSame([], $profiles->roleKeys(UserSystemRoleEnum::SYSTEM));

        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'creation_permissions' => [Permission::CREATE_VENUE->value],
                'privileged_permissions' => [
                    Permission::REVIEW_PROFILE_CHANGES->value,
                    Permission::WALLET_USER_BALANCE_CREDIT->value,
                ],
                'backoffice_permissions' => [
                    Permission::BACKOFFICE_ACCESS->value,
                    Permission::BACKOFFICE_VENUE_MANAGE->value,
                ],
            ])
            ->callAction('savePermissionsInline', data: ['reason' => 'Назначение прав системному пользователю'])
            ->assertHasNoFormErrors();

        $checker = app(UserOperationalPermissionChecker::class);
        foreach ([Permission::CREATE_VENUE, Permission::REVIEW_PROFILE_CHANGES,
            Permission::WALLET_USER_BALANCE_CREDIT, Permission::BACKOFFICE_VENUE_MANAGE] as $permission) {
            $this->assertTrue($checker->allows($target->fresh(), $permission));
        }
        $this->assertDatabaseHas('review_permission_cursors', [
            'user_id' => $target->id, 'permission' => Permission::REVIEW_PROFILE_CHANGES->value,
        ]);
        $this->assertFalse($checker->allows($target->fresh(), Permission::CREATE_TEAM));
    }

    public function test_permission_expansion_preserves_existing_builder_grants_without_activating_dormant_grants(): void
    {
        $system = $this->user(UserSystemRoleEnum::SYSTEM);
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $system->operationalPermissions()->createMany([
            ['permission' => Permission::BACKOFFICE_BUILDER_VIEW, 'is_allowed' => true],
            ['permission' => Permission::WALLET_USER_BALANCE_CREDIT, 'is_allowed' => true],
        ]);
        $admin->operationalPermissions()->create([
            'permission' => Permission::WALLET_USER_BALANCE_CREDIT, 'is_allowed' => true,
        ]);

        $migration = require database_path('migrations/2026_11_07_160000_disable_dormant_system_permission_grants.php');
        $migration->up();

        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertTrue($checker->allows($system, Permission::BACKOFFICE_BUILDER_VIEW));
        $this->assertFalse($checker->allows($system, Permission::WALLET_USER_BALANCE_CREDIT));
        $this->assertTrue($checker->allows($admin, Permission::WALLET_USER_BALANCE_CREDIT));
    }

    public function test_entry_permission_migration_preserves_system_access_and_confirmation_rules(): void
    {
        $system = $this->user(UserSystemRoleEnum::SYSTEM);
        $system->update(['status' => UserStatusEnum::UNCONFIRMED]);
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $admin->update(['status' => UserStatusEnum::UNCONFIRMED]);
        $system->operationalPermissions()->create([
            'permission' => Permission::BACKOFFICE_ACCESS, 'is_allowed' => false,
        ]);
        $admin->operationalPermissions()->create([
            'permission' => Permission::BACKOFFICE_ACCESS, 'is_allowed' => true,
        ]);
        \Illuminate\Support\Facades\DB::table('user_operational_permissions')->insert([
            'user_id' => $system->id, 'permission' => 'backoffice.system_access', 'is_allowed' => true,
        ]);

        $migration = require database_path('migrations/2026_11_07_170000_unify_filament_entry_permission.php');
        $migration->up();

        $panel = Filament::getPanel('backoffice');
        $this->assertTrue($system->canAccessPanel($panel));
        $this->assertFalse($admin->canAccessPanel($panel));
        $this->assertDatabaseMissing('user_operational_permissions', [
            'user_id' => $system->id, 'permission' => 'backoffice.system_access',
        ]);
    }

    public function test_delegated_admin_cannot_assign_system_role(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        app(GlobalOperationalPermissionManager::class)->sync($super, $admin, [], [
            Permission::MANAGE_SYSTEM_ROLES->value,
        ]);
        $this->assertTrue($admin->can('manage-user-system-role', $target));

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(\App\Modules\Identity\Application\UseCases\AdminUpdateUserSystemRoleHandler::class)
            ->handle($admin, $target->id, UserSystemRoleEnum::SYSTEM);
    }

    public function test_confirmation_rejection_explains_missing_verified_primary_contact(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::ADMIN);
        $target->update(['status' => UserStatusEnum::UNCONFIRMED]);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['account_status' => UserStatusEnum::CONFIRMED->value])
            ->callAction('saveGeneralInline', data: ['reason' => 'Проверка подтверждения нового аккаунта'])
            ->assertHasNoFormErrors();

        Notification::assertNotified('Не удалось изменить статус');
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $target->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'auditable_id' => $target->id, 'event' => 'admin_user_status_changed',
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
