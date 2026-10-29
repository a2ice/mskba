<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\EditBackofficeUser;
use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Dashboard\Domain\Models\DashboardButtonStyle;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserOperationalPermissionsHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

final class BackofficePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
        $user->createProfile([]);

        return $user;
    }

    /** @param list<Permission> $permissions */
    private function grant(User $actor, User $target, array $permissions, bool $overrideInherited = false): void
    {
        app(BackofficePermissionManager::class)->sync(
            $actor, $target,
            array_map(static fn (Permission $permission): string => $permission->value, $permissions),
            $overrideInherited,
        );
    }

    public function test_superadmin_can_override_inherited_admin_backoffice_baseline(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $widget = AccountDashboardWidget::query()->where('kind', 'getting_started')->firstOrFail();
        $this->assertTrue($admin->canAccessPanel(app(Panel::class)->id('backoffice')));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $widget));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $widget));

        $this->grant($super, $admin, [Permission::BACKOFFICE_ACCESS, Permission::BACKOFFICE_WIDGET_VIEW], true);
        $this->assertTrue($admin->canAccessPanel(app(Panel::class)->id('backoffice')));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $widget));

        $this->grant($super, $admin, [], true);
        $this->assertFalse($admin->canAccessPanel(app(Panel::class)->id('backoffice')));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $widget));
        $this->actingAs($admin)->get('/backoffice')->assertForbidden();
    }

    public function test_delegated_editor_can_edit_a_regular_widget_without_becoming_admin(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $editor = $this->user(UserSystemRoleEnum::EDITOR);
        $this->grant($super, $editor, [
            Permission::BACKOFFICE_ACCESS, Permission::BACKOFFICE_WIDGET_VIEW,
            Permission::BACKOFFICE_WIDGET_EDIT,
        ]);

        $this->assertSame(UserSystemRoleEnum::EDITOR, $editor->fresh()->system_role);
        $this->assertTrue($editor->canAccessPanel(app(Panel::class)->id('backoffice')));
        $widget = AccountDashboardWidget::query()->where('kind', 'getting_started')->firstOrFail();
        $this->assertTrue(Gate::forUser($editor)->allows('update', $widget));
        $this->assertFalse(Gate::forUser($editor)->allows('create', AccountDashboardWidget::class));
        $this->assertFalse(Gate::forUser($editor)->allows('delete', $widget));

        $this->actingAs($editor)->get('/backoffice/account-dashboard-widgets')->assertOk();
        $this->get('/backoffice/account-dashboard-widgets/'.$widget->id.'/edit')->assertOk();
        $this->get('/backoffice/account-dashboard-widgets/create')->assertForbidden();
        $this->get('/backoffice/backoffice-users')->assertForbidden();

        // The older administrative controller must not act as a bypass.
        $this->get(route('admin.account-dashboard.index'))->assertForbidden();
    }

    public function test_delegated_user_cannot_access_executable_html_even_with_widget_edit_permission(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $editor = $this->user(UserSystemRoleEnum::EDITOR);
        $this->grant($super, $editor, [
            Permission::BACKOFFICE_ACCESS, Permission::BACKOFFICE_WIDGET_VIEW,
            Permission::BACKOFFICE_WIDGET_CREATE, Permission::BACKOFFICE_WIDGET_EDIT,
        ]);
        $widget = AccountDashboardWidget::query()->create([
            'kind' => 'custom_html', 'title' => 'Системный код',
            'sort_order' => 5, 'size' => 'half', 'enabled' => true, 'can_hide' => true,
            'contact_filter' => 'any',
        ]);
        $this->assertFalse(Gate::forUser($editor)->allows('update', $widget));
        $this->actingAs($editor)->get('/backoffice/account-dashboard-widgets/'.$widget->id.'/edit')->assertNotFound();
    }

    public function test_style_permissions_are_separate_from_widget_permissions(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $editor = $this->user(UserSystemRoleEnum::EDITOR);
        $style = DashboardButtonStyle::query()->create([
            'name' => 'Зелёный', 'class_name' => 'bg-green',
            'css_code' => '.bg-green { background-color: green !important; }',
            'enabled' => true,
        ]);
        $this->grant($super, $editor, [
            Permission::BACKOFFICE_ACCESS, Permission::BACKOFFICE_STYLE_VIEW,
        ]);
        $this->assertTrue(Gate::forUser($editor)->allows('viewAny', DashboardButtonStyle::class));
        $this->assertFalse(Gate::forUser($editor)->allows('update', $style));
        $this->actingAs($editor)->get('/backoffice/dashboard-button-styles')->assertOk();
        $this->get('/backoffice/dashboard-button-styles/'.$style->id.'/edit')->assertForbidden();
        $this->flushSession();
        $this->grant($super, $editor, [
            Permission::BACKOFFICE_ACCESS, Permission::BACKOFFICE_STYLE_VIEW,
            Permission::BACKOFFICE_STYLE_MANAGE,
        ]);
        $this->actingAs($editor)->get('/backoffice/dashboard-button-styles/'.$style->id.'/edit')->assertOk();
    }

    public function test_filament_permission_editor_writes_only_backoffice_snapshots(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::MANAGER);
        UserOperationalPermission::query()->create([
            'user_id' => $target->id,
            'permission' => Permission::HANDLE_SUPPORT_TICKETS,
            'is_allowed' => true,
        ]);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->get('/backoffice/backoffice-users')->assertOk();

        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['change_reason' => 'Проверка редактирования разрешений Filament', 'backoffice_permissions' => [
                Permission::BACKOFFICE_WIDGET_VIEW->value,
                Permission::BACKOFFICE_WIDGET_EDIT->value,
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $manager = app(BackofficePermissionManager::class);
        $this->assertTrue($manager->allows($target, Permission::BACKOFFICE_ACCESS));
        $this->assertTrue($manager->allows($target, Permission::BACKOFFICE_WIDGET_EDIT));
        $this->assertFalse($manager->allows($target, Permission::BACKOFFICE_WIDGET_CREATE));
        $this->assertTrue(UserOperationalPermission::query()->where('user_id', $target->id)
            ->where('permission', Permission::HANDLE_SUPPORT_TICKETS->value)->value('is_allowed'));
        $this->assertSame(UserSystemRoleEnum::MANAGER, $target->fresh()->system_role);
    }

    public function test_ordinary_admin_cannot_grant_filament_rights_in_legacy_bulk_handler(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);

        app(AdminUpdateUserOperationalPermissionsHandler::class)->handle($admin, $target->id, [
            Permission::CREATE_TEAM,
            Permission::BACKOFFICE_ACCESS,
            Permission::BACKOFFICE_WIDGET_EDIT,
        ]);
        $this->assertFalse(app(BackofficePermissionManager::class)->allows($target, Permission::BACKOFFICE_ACCESS));

        $this->expectException(AuthorizationException::class);
        app(BackofficePermissionManager::class)->sync(
            $admin, $target, [Permission::BACKOFFICE_ACCESS->value],
        );
    }

    public function test_superadmin_is_not_lockable_and_blocked_users_cannot_access_panel(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $otherSuper = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $editor = $this->user(UserSystemRoleEnum::EDITOR);
        $manager = app(BackofficePermissionManager::class);

        $this->expectException(AuthorizationException::class);
        $manager->sync($super, $otherSuper, []);
    }

    public function test_blocked_account_and_unconfirmed_account_cannot_use_granted_backoffice_permissions(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $blocked = $this->user(UserSystemRoleEnum::EDITOR);
        $unconfirmed = $this->user(UserSystemRoleEnum::MODERATOR);

        foreach ([$blocked, $unconfirmed] as $target) {
            $this->grant($super, $target, [
                Permission::BACKOFFICE_ACCESS, Permission::BACKOFFICE_WIDGET_VIEW,
            ]);
        }
        $blocked->update(['status' => UserStatusEnum::BLOCKED]);
        $unconfirmed->update(['status' => UserStatusEnum::UNCONFIRMED]);

        $this->assertFalse($blocked->fresh()->canAccessPanel(app(Panel::class)->id('backoffice')));
        $this->assertFalse($unconfirmed->fresh()->canAccessPanel(app(Panel::class)->id('backoffice')));
    }

    public function test_superadmin_cannot_be_locked_out_even_by_explicit_denial_snapshot(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        UserOperationalPermission::query()->create([
            'user_id' => $super->id,
            'permission' => Permission::BACKOFFICE_ACCESS,
            'is_allowed' => false,
        ]);
        $this->assertTrue($super->canAccessPanel(app(Panel::class)->id('backoffice')));
    }

}
