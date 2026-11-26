<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\EditBackofficeUser;
use App\Modules\Identity\Application\UseCases\SetUserPasswordHandler;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

final class UserPasswordManagementTest extends TestCase
{
    use RefreshDatabase;

    private function person(UserSystemRoleEnum $role): User
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
            'password' => 'AdminStrong1!',
        ]);
        $user->createProfile([]);

        return $user;
    }

    public function test_superadmin_can_set_another_users_temporary_password_from_filament(): void
    {
        $admin = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->person(UserSystemRoleEnum::USER);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
            ->assertActionVisible('setUserPassword')
            ->callAction('setUserPassword', data: [
                'admin_password' => 'AdminStrong1!',
                'new_password' => 'NewStrong2!',
                'new_password_confirmation' => 'NewStrong2!',
                'reason' => 'Запрос владельца на смену пароля',
            ])
            ->assertHasNoActionErrors();

        $target->refresh();
        $this->assertTrue(Hash::check('NewStrong2!', $target->password));
        $this->assertTrue($target->is_temporary_password);
    }

    public function test_password_action_reports_invalid_credentials_and_keeps_old_password(): void
    {
        $admin = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->person(UserSystemRoleEnum::SYSTEM);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $oldHash = $target->password;

        foreach ([
            ['admin_password' => 'Incorrect3!', 'new_password' => 'NewStrong2!', 'new_password_confirmation' => 'NewStrong2!'],
            ['admin_password' => 'AdminStrong1!', 'new_password' => 'NewStrong2!', 'new_password_confirmation' => 'OtherStrong3!'],
            ['admin_password' => 'AdminStrong1!', 'new_password' => 'lowercase123', 'new_password_confirmation' => 'lowercase123'],
        ] as $values) {
            Livewire::test(EditBackofficeUser::class, ['record' => $target->getRouteKey()])
                ->assertActionVisible('setUserPassword')
                ->callAction('setUserPassword', data: $values + ['reason' => 'Проверка отображения ошибок'])
                ->assertNotified('Пароль не установлен');
            $this->assertSame($oldHash, $target->fresh()->password);
        }
    }

    public function test_other_roles_cannot_access_the_superadmin_password_action(): void
    {
        $admin = $this->person(UserSystemRoleEnum::ADMIN);
        $target = $this->person(UserSystemRoleEnum::USER);

        $this->expectException(AuthorizationException::class);
        app(SetUserPasswordHandler::class)->handleAdministrator($admin, $target, 'StrongOther2!');
    }

    public function test_filament_password_action_is_not_visible_to_other_roles(): void
    {
        $admin = $this->person(UserSystemRoleEnum::ADMIN);
        $target = $this->person(UserSystemRoleEnum::USER);
        $this->actingAs($admin);
        $this->assertFalse(\App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource::canEdit($target));
    }
}
