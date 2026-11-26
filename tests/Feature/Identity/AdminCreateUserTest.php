<?php

namespace Tests\Feature\Identity;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\ListBackofficeUsers;
use App\Modules\Identity\Application\UseCases\AdminCreateUserHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class AdminCreateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_creates_minimal_unconfirmed_account_with_audit(): void
    {
        $actor = $this->actor(Role::SUPERADMIN);
        $user = app(AdminCreateUserHandler::class)->handle($actor, 'Ivanov777', 'TestPass1!', 'TestPass1!', true);

        $this->assertSame('ivanov777', $user->username);
        $this->assertSame(Role::USER, $user->system_role);
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->status);
        $this->assertTrue(Hash::check('TestPass1!', $user->password));
        $this->assertNotSame('TestPass1!', $user->password);
        $this->assertNotNull($user->password_updated_at);
        $this->assertTrue($user->is_temporary_password);
        $this->assertFalse($user->consents()->exists());
        $audit = \App\Modules\Audit\Domain\Models\AuditLog::query()->where('auditable_id', $user->id)->where('event', 'admin_user_created')->sole();
        $this->assertTrue($audit->metadata['consent_attested_by_admin']);
        $this->assertSame($actor->id, $audit->metadata['actor_user_id']);
        $this->assertArrayNotHasKey('password', $audit->new_values);
        $this->assertNotNull($user->profile);
        $this->assertFalse($user->contacts()->exists());
        $this->assertFalse($user->operationalPermissions()->exists());
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'event' => 'admin_user_created',
        ]);
    }

    public function test_admin_needs_creation_permission_and_editor_access(): void
    {
        $actor = $this->actor(Role::ADMIN);
        $handler = app(AdminCreateUserHandler::class);
        foreach ([Permission::BACKOFFICE_ACCESS, Permission::EDIT_USER_PERSONAL_DATA] as $permission) {
            $actor->operationalPermissions()->create(['permission' => $permission->value, 'is_allowed' => true]);
        }
        $this->assertFalse($handler->canCreate($actor));
        try {
            $handler->handle($actor, 'forbidden_user', 'TestPass1!', 'TestPass1!', true);
            $this->fail('Creation must require its own permission.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('users', ['username' => 'forbidden_user']);
        }

        $actor->operationalPermissions()->create([
            'permission' => Permission::BACKOFFICE_USER_CREATE->value, 'is_allowed' => true,
        ]);
        $this->assertTrue($handler->canCreate($actor));
        $this->assertSame(Role::USER, $handler->handle($actor, 'allowed_user', 'TestPass1!', 'TestPass1!', true)->system_role);

        $actor->operationalPermissions()->where('permission', Permission::EDIT_USER_PERSONAL_DATA->value)
            ->update(['is_allowed' => false]);
        $this->assertFalse($handler->canCreate($actor));
    }

    public function test_unconfirmed_or_lower_role_cannot_create_accounts(): void
    {
        $handler = app(AdminCreateUserHandler::class);
        $this->assertFalse($handler->canCreate(null));
        $this->assertFalse($handler->canCreate($this->actor(Role::USER)));
        $actor = $this->actor(Role::SUPERADMIN);
        $actor->forceFill(['status' => UserStatusEnum::UNCONFIRMED])->save();
        $this->assertFalse($handler->canCreate($actor));
    }

    public function test_taken_or_invalid_logins_are_rejected(): void
    {
        $actor = $this->actor(Role::SUPERADMIN);
        User::factory()->create(['username' => 'IVANOV'])->delete();
        $holder = User::factory()->create(['username' => 'different_login']);
        $holder->forceFill(['nickname' => 'busy_nickname'])->save();

        foreach (['ivanov', 'busy_nickname', 'a!'] as $username) {
            try {
                app(AdminCreateUserHandler::class)->handle($actor, $username, 'TestPass1!', 'TestPass1!', true);
                $this->fail('Invalid or occupied login must not be created.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('username', $exception->errors());
            }
        }
        $this->assertDatabaseCount('users', 3);
    }

    public function test_filament_action_creates_and_redirects_to_existing_editor(): void
    {
        $actor = $this->actor(Role::SUPERADMIN);
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $component = Livewire::test(ListBackofficeUsers::class)
            ->callAction('createUser', data: ['username' => 'filament_new_user', 'password' => 'TestPass1!', 'password_confirmation' => 'TestPass1!', 'consent_attested' => true])
            ->assertHasNoFormErrors();

        $user = User::query()->where('username', 'filament_new_user')->firstOrFail();
        $component->assertRedirect(BackofficeUserResource::getUrl('edit', ['record' => $user]));
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->status);
    }

    public function test_invalid_password_mismatch_and_missing_attestation_are_rejected(): void
    {
        $actor = $this->actor(Role::SUPERADMIN);
        $handler = app(AdminCreateUserHandler::class);
        foreach ([
            ['weak', 'weak', true, 'password'],
            ['TestPass1!', 'Different1!', true, 'password_confirmation'],
            ['TestPass1!', 'TestPass1!', false, 'consent_attested'],
        ] as [$password, $confirmation, $attested, $field]) {
            try {
                $handler->handle($actor, 'new_user', $password, $confirmation, $attested);
                $this->fail('Invalid administrative creation must fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
        $this->assertDatabaseMissing('users', ['username' => 'new_user']);
    }

    private function actor(Role $role): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
    }
}
