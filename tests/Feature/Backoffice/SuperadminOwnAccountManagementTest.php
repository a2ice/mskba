<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\EditBackofficeUser;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\UseCases\AdminEditPersonalProfileHandler;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserPermissionSnapshotHandler;
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

final class SuperadminOwnAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private function person(UserSystemRoleEnum $role): User
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
        $user->createProfile([]);

        return $user;
    }

    public function test_superadmin_sees_own_record_in_the_user_directory_but_not_other_superadmins_or_system_accounts(): void
    {
        $super = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $otherSuper = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->person(UserSystemRoleEnum::ADMIN);
        $system = $this->person(UserSystemRoleEnum::SYSTEM);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $ids = BackofficeUserResource::getEloquentQuery()->pluck('id')->all();
        $this->assertContains($super->id, $ids);
        $this->assertContains($admin->id, $ids);
        $this->assertNotContains($otherSuper->id, $ids);
        $this->assertNotContains($system->id, $ids);

        $this->assertTrue(BackofficeUserResource::canEdit($super));
        $this->assertFalse(BackofficeUserResource::canEdit($otherSuper));
        $this->get(BackofficeUserResource::getUrl('edit', ['record' => $super]))
            ->assertOk();
    }

    public function test_superadmin_can_edit_own_profile_but_cannot_change_own_permissions(): void
    {
        $super = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        app(AdminEditPersonalProfileHandler::class)->handle(
            $super, $super, ['first_name' => 'Александр'],
            'Корректировка собственных персональных данных',
        );
        $this->assertSame('Александр', $super->profile()->firstOrFail()->first_name);

        Livewire::test(EditBackofficeUser::class, ['record' => $super->getRouteKey()])
            ->assertActionHidden('savePermissionsInline')
            ->assertActionHidden('saveGeneralInline')
            ->assertActionVisible('savePersonalInline');

        $this->expectException(AuthorizationException::class);
        app(AdminUpdateUserPermissionSnapshotHandler::class)->handle(
            $super, $super, [], [], [], 'Попытка отзыва собственных системных прав',
        );
    }

    public function test_all_superadmin_permissions_remain_effective_even_when_explicitly_denied(): void
    {
        $super = $this->person(UserSystemRoleEnum::SUPERADMIN);
        UserOperationalPermission::query()->create([
            'user_id' => $super->id,
            'permission' => Permission::CREATE_TEAM,
            'is_allowed' => false,
        ]);
        $this->actingAs($super);

        $checker = app(UserOperationalPermissionChecker::class);
        foreach (Permission::cases() as $permission) {
            $this->assertTrue($checker->allows($super, $permission), $permission->value);
        }

        $all = app(AdminUpdateUserPermissionSnapshotHandler::class)->snapshot($super);
        $this->assertCount(count(Permission::cases()), $all['permissions']);
        $this->assertSame(UserSystemRoleEnum::SUPERADMIN->value, $all['role']);
    }

    public function test_regular_admin_cannot_edit_own_record_or_escalate_to_a_superadmin(): void
    {
        $admin = $this->person(UserSystemRoleEnum::ADMIN);
        $super = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $this->assertFalse(BackofficeUserResource::canEdit($admin));
        $this->assertFalse(BackofficeUserResource::canEdit($super));
        $this->assertNotContains(
            $super->id,
            BackofficeUserResource::getEloquentQuery()->pluck('id')->all(),
        );
    }
}
