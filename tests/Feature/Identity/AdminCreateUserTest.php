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

    public function test_test_accounts_allocate_monotonic_numbers_and_simulated_evidence(): void
    {
        $actor = $this->actor(Role::SUPERADMIN);
        $handler = app(AdminCreateUserHandler::class);
        $first = $handler->handle($actor, 'fixture_first', 'TestPass1!', 'TestPass1!', true, true);
        $this->assertTrue($first->is_test_account);
        $this->assertSame(1, $first->test_account_number);
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $first->status);
        $this->assertFalse($first->contacts()->exists());
        $this->assertSame('filament_test_fixture', $first->consents()->sole()->source);
        $this->assertTrue($first->consents()->sole()->payload['simulated']);
        $first->delete();

        \Illuminate\Support\Facades\Event::fake([\App\Modules\Identity\Domain\Events\UserAccountConfirmed::class]);
        $second = $handler->handle($actor, 'fixture_second', 'TestPass1!', 'TestPass1!', true, true, [
            'confirmed' => true, 'contact_type' => 'email', 'contact_value' => 'test_2@mskba.ru',
            'contact_verified' => true,
        ]);
        $this->assertSame(2, $second->test_account_number);
        $this->assertSame(UserStatusEnum::CONFIRMED, $second->status);
        $contact = $second->contacts()->sole();
        $this->assertSame('test_2@mskba.ru', $contact->value);
        $this->assertTrue($contact->is_primary);
        $this->assertFalse($contact->is_public);
        $this->assertNotNull($contact->verified_at);
        $this->assertSame('filament_test_fixture', $contact->meta['verification_source']);
        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Modules\Identity\Domain\Events\UserAccountConfirmed::class);
    }

    public function test_test_contact_can_remain_unverified_and_consent_is_optional_without_confirmation(): void
    {
        $actor = $this->actor(Role::SUPERADMIN);
        $handler = app(AdminCreateUserHandler::class);
        $user = $handler->handle($actor, 'no_consent_fixture', 'TestPass1!', 'TestPass1!', false, true);
        $this->assertFalse($user->consents()->exists());
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->status);

        $user = $handler->handle($actor, 'unverified_fixture', 'TestPass1!', 'TestPass1!', true, true, [
            'confirmed' => true, 'contact_type' => 'email', 'contact_value' => 'unverified@example.test',
            'contact_verified' => false,
        ]);
        $this->assertSame(UserStatusEnum::CONFIRMED, $user->status);
        $this->assertNull($user->contacts()->sole()->verified_at);
    }

    public function test_invalid_fixture_requests_do_not_consume_number_or_create_relations(): void
    {
        $actor = $this->actor(Role::SUPERADMIN);
        $handler = app(AdminCreateUserHandler::class);
        $owner = User::factory()->create();
        $owner->contacts()->create([
            'type' => 'email', 'value' => 'occupied@example.test', 'is_primary' => true,
        ]);
        foreach ([
            [false, 'test_1@mskba.ru', true],
            [true, 'arbitrary@example.test', true],
            [true, 'occupied@example.test', false],
            [true, 'test_99@mskba.ru', true],
        ] as [$consent, $email, $verified]) {
            try {
                $handler->handle($actor, 'invalid_fixture', 'TestPass1!', 'TestPass1!', $consent, true, [
                    'confirmed' => true, 'contact_type' => 'email', 'contact_value' => $email,
                    'contact_verified' => $verified,
                ]);
                $this->fail('Invalid fixture should be rejected.');
            } catch (ValidationException) {
                $this->assertDatabaseMissing('users', ['username' => 'invalid_fixture']);
                $this->assertDatabaseHas('test_account_counters', ['id' => 1, 'last_number' => 0]);
            }
        }
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_test_creation_is_not_available_to_delegated_admin(): void
    {
        $actor = $this->actor(Role::ADMIN);
        foreach ([Permission::BACKOFFICE_ACCESS, Permission::EDIT_USER_PERSONAL_DATA, Permission::BACKOFFICE_USER_CREATE] as $permission) {
            $actor->operationalPermissions()->create(['permission' => $permission->value, 'is_allowed' => true]);
        }
        $handler = app(AdminCreateUserHandler::class);
        $this->assertTrue($handler->canCreate($actor));
        $this->assertFalse($handler->canCreateTest($actor));

        $this->expectException(AuthorizationException::class);
        $handler->handle($actor, 'denied_fixture', 'TestPass1!', 'TestPass1!', true, true);
    }

    public function test_fixture_creation_rolls_back_if_contact_handler_fails(): void
    {
        $actor = $this->actor(Role::SUPERADMIN);
        $this->mock(\App\Modules\Contact\Application\UseCases\CreateContactForUserHandler::class,
            fn ($mock) => $mock->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Injected failure')));
        try {
            app(AdminCreateUserHandler::class)->handle($actor, 'rolled_back_fixture', 'TestPass1!', 'TestPass1!', true, true, [
                'confirmed' => true, 'contact_type' => 'email', 'contact_value' => 'test_1@mskba.ru',
                'contact_verified' => true,
            ]);
            $this->fail('Injected failure must abort fixture creation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected failure', $exception->getMessage());
        }
        $this->assertDatabaseMissing('users', ['username' => 'rolled_back_fixture']);
        $this->assertDatabaseHas('test_account_counters', ['id' => 1, 'last_number' => 0]);
        $this->assertDatabaseCount('user_consents', 0);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'admin_user_created']);
    }

    private function actor(Role $role): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
    }
}
