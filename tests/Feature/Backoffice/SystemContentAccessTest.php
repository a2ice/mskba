<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum as Status;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class SystemContentAccessTest extends TestCase
{
    use RefreshDatabase;

    private function grant(User $user, Permission $permission, bool $allowed = true): void
    {
        UserOperationalPermission::query()->updateOrCreate(
            ['user_id' => $user->id, 'permission' => $permission->value],
            ['is_allowed' => $allowed],
        );
    }

    public function test_system_content_access_requires_both_explicit_grants_not_account_confirmation(): void
    {
        $system = User::factory()->create(['system_role' => Role::SYSTEM, 'status' => Status::UNCONFIRMED]);
        $this->assertFalse(Gate::forUser($system)->allows('manage-content'));

        $this->grant($system, Permission::BACKOFFICE_ACCESS);
        $this->assertFalse(Gate::forUser($system)->allows('manage-content'));

        $this->grant($system, Permission::CONTENT_MANAGE);
        $this->assertTrue(Gate::forUser($system)->allows('manage-content'));
        $this->assertFalse(Gate::forUser($system)->allows('access-admin-panel'));
        $this->assertSame(Status::UNCONFIRMED, $system->fresh()->status);

        $this->grant($system, Permission::BACKOFFICE_ACCESS, false);
        $this->assertFalse(Gate::forUser($system)->allows('manage-content'));
        $this->grant($system, Permission::BACKOFFICE_ACCESS);
        $this->grant($system, Permission::CONTENT_MANAGE, false);
        $this->assertFalse(Gate::forUser($system)->allows('manage-content'));
        $this->grant($system, Permission::CONTENT_MANAGE);

        $system->update(['status' => Status::BLOCKED]);
        $this->assertFalse(Gate::forUser($system)->allows('manage-content'));
        $system->update(['status' => Status::UNCONFIRMED]);
        $system->delete();
        $this->assertFalse(Gate::forUser($system)->allows('manage-content'));
    }

    public function test_non_system_editor_still_requires_confirmation(): void
    {
        $editor = User::factory()->create(['system_role' => Role::ADMIN, 'status' => Status::UNCONFIRMED]);
        $this->grant($editor, Permission::CONTENT_MANAGE);
        $this->grant($editor, Permission::BACKOFFICE_ACCESS);
        $this->assertFalse(Gate::forUser($editor)->allows('manage-content'));
        $editor->update(['status' => Status::CONFIRMED]);
        $this->assertTrue(Gate::forUser($editor)->allows('manage-content'));
    }
}
