<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\EditBackofficeUser;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class UnifiedPermissionsCenterTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED, 'system_role' => $role,
        ]);
        $user->createProfile([]);

        return $user;
    }

    public function test_superadmin_can_manage_creation_support_and_filament_without_changing_system_role(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::MANAGER);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $this->get('/backoffice/backoffice-users')->assertOk();

        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'change_reason' => 'Проверка управления правами пользователя',
                'creation_permissions' => [
                    Permission::CREATE_TOURNAMENT->value,
                ],
                'privileged_permissions' => [Permission::HANDLE_SUPPORT_TICKETS->value],
                'backoffice_permissions' => [
                    Permission::BACKOFFICE_WIDGET_VIEW->value,
                ],
            ])
            ->call('save')->assertHasNoFormErrors();

        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertTrue($checker->allows($target, Permission::CREATE_TOURNAMENT));
        $this->assertTrue($checker->allows($target, Permission::HANDLE_SUPPORT_TICKETS));
        $this->assertFalse($checker->allows($target, Permission::BACKOFFICE_ACCESS));
        // Explicit individual denial now overrides a normal inherited manager baseline.
        $this->assertFalse($checker->allows($target, Permission::CREATE_COORDINATION));
        $this->assertSame(UserSystemRoleEnum::MANAGER, $target->fresh()->system_role);
    }

    public function test_admin_can_delegate_only_own_rights_to_lower_role(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::MANAGER);
        $global = app(GlobalOperationalPermissionManager::class);
        $global->sync($super, $admin, $global->granted($admin), [
            Permission::EDIT_USER_PERSONAL_DATA->value,
            Permission::REVIEW_PROFILE_CHANGES->value,
            Permission::EDIT_OWN_PROFILE->value,
        ]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $this->get('/backoffice/backoffice-users')->assertOk();
        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'change_reason' => 'Проверка управления правами пользователя',
                'creation_permissions' => [Permission::CREATE_EVENT->value],
                'privileged_permissions' => [],
                'backoffice_permissions' => [],
            ])
            ->call('save')->assertHasNoFormErrors();

        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertTrue($checker->allows($target, Permission::CREATE_EVENT));
        $this->assertFalse($checker->allows($target, Permission::HANDLE_SUPPORT_TICKETS));
        $this->assertTrue($checker->allows($target, Permission::BACKOFFICE_ACCESS));
    }

    public function test_admin_cannot_manage_equal_higher_or_system_role_accounts(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $peer = $this->user(UserSystemRoleEnum::ADMIN);
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $editor = $this->user(UserSystemRoleEnum::MANAGER);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $this->get('/backoffice/backoffice-users/'.$peer->id.'/edit')->assertNotFound();
        $this->get('/backoffice/backoffice-users/'.$super->id.'/edit')->assertNotFound();
        $this->get('/backoffice/backoffice-users/'.$editor->id.'/edit')->assertForbidden();
    }

    public function test_critical_permissions_cannot_be_granted_by_admin_via_service(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $manager = app(GlobalOperationalPermissionManager::class);
        $this->expectException(AuthorizationException::class);
        $manager->sync($admin, $target, [], [Permission::HANDLE_SUPPORT_TICKETS->value]);
    }

    public function test_entity_scoped_permissions_are_not_inserted_into_global_permission_rows(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $manager = app(GlobalOperationalPermissionManager::class);
        $manager->sync($super, $target, [Permission::CREATE_TEAM->value], []);

        $keys = UserOperationalPermission::query()->where('user_id', $target->id)
            ->pluck('permission')->map(fn ($p): string => $p instanceof Permission ? $p->value : (string) $p)->all();
        $this->assertNotContains('team.settings.edit', $keys);
        $this->assertNotContains('event.update', $keys);
        $this->assertNotContains('view', $keys);
    }

    public function test_explicit_denials_and_grants_preserve_sources_and_unrelated_filament_rights(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::EDITOR);
        app(BackofficePermissionManager::class)->sync($super, $target, [
            Permission::BACKOFFICE_ACCESS->value,
        ]);

        app(GlobalOperationalPermissionManager::class)->sync($super, $target, [], []);
        $sources = app(GlobalOperationalPermissionManager::class)->sources($target);
        $this->assertSame('Явно запрещено', $sources[Permission::CREATE_TEAM->value]);
        $this->assertTrue(app(BackofficePermissionManager::class)->allows($target, Permission::BACKOFFICE_ACCESS));

        app(GlobalOperationalPermissionManager::class)->sync($super, $target, [
            Permission::CREATE_TEAM->value,
        ], []);
        $sources = app(GlobalOperationalPermissionManager::class)->sources($target);
        $this->assertSame('Выдано индивидуально', $sources[Permission::CREATE_TEAM->value]);
    }

    public function test_unconfirmed_admin_is_denied_permissions_editor(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $admin->update(['status' => UserStatusEnum::UNCONFIRMED]);
        $this->assertFalse(app(GlobalOperationalPermissionManager::class)->mayManage($admin));
    }

    public function test_role_change_is_a_separate_action_that_preserves_individual_permission_overrides(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user();
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        app(GlobalOperationalPermissionManager::class)->sync(
            $super, $target, [Permission::CREATE_TEAM->value], [],
        );
        $before = UserOperationalPermission::query()
            ->where('user_id', $target->id)
            ->orderBy('permission')
            ->get(['permission', 'is_allowed'])
            ->toArray();

        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->assertActionVisible('changeSystemRole')
            ->callAction('changeSystemRole', data: [
                'new_system_role' => UserSystemRoleEnum::MANAGER->value,
                'reason' => 'Назначение менеджера по проверке прав',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(UserSystemRoleEnum::MANAGER, $target->fresh()->system_role);
        $after = UserOperationalPermission::query()
            ->where('user_id', $target->id)
            ->orderBy('permission')
            ->get(['permission', 'is_allowed'])
            ->toArray();
        $this->assertSame($before, $after);
    }

    public function test_switching_to_user_role_removes_staff_role_using_existing_handler(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::MANAGER);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->callAction('changeSystemRole', data: [
                'new_system_role' => UserSystemRoleEnum::USER->value,
                'reason' => 'Снятие административной роли по заявлению',
            ])->assertHasNoFormErrors();

        $this->assertSame(UserSystemRoleEnum::USER, $target->fresh()->system_role);
    }

    public function test_role_action_is_hidden_from_admin_without_explicit_role_management_right(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user();
        app(GlobalOperationalPermissionManager::class)->sync($super, $admin, [], [
            Permission::EDIT_USER_PERSONAL_DATA->value,
        ]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->assertActionHidden('changeSystemRole');
        $this->assertSame(UserSystemRoleEnum::USER, $target->fresh()->system_role);
    }

    public function test_filament_role_action_rejects_service_and_superadmin_roles(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user();
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        foreach ([UserSystemRoleEnum::SYSTEM, UserSystemRoleEnum::SUPERADMIN] as $forbidden) {
            Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
                ->callAction('changeSystemRole', data: [
                    'new_system_role' => $forbidden->value,
                    'reason' => 'Проверка запрета назначения служебной роли',
                ])
                ->assertHasFormErrors(['new_system_role']);

            $this->assertSame(UserSystemRoleEnum::USER, $target->fresh()->system_role);
        }
    }
}
