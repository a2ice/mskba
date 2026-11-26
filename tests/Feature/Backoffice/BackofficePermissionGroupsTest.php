<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficePermissionGroups;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class BackofficePermissionGroupsTest extends TestCase
{
    public function test_every_backoffice_permission_is_present_exactly_once_and_round_trips(): void
    {
        $keys = array_map(static fn (Permission $permission): string => $permission->value, Permission::backofficeCases());
        $grouped = BackofficePermissionGroups::split($keys);
        $flattened = BackofficePermissionGroups::flatten($grouped);

        $this->assertEqualsCanonicalizing($keys, $flattened);
        $this->assertSame(count($flattened), count(array_unique($flattened)));
        $this->assertContains(Permission::BACKOFFICE_USER_CREATE->value, $grouped['users']);
        $this->assertContains(Permission::BACKOFFICE_INVITATION_ISSUE->value, $grouped['invitations']);
    }

    public function test_system_role_catalog_covers_all_permissions_and_shares_backoffice_groups(): void
    {
        $keys = array_map(static fn (Permission $permission): string => $permission->value, Permission::cases());
        $groups = BackofficePermissionGroups::split($keys, includeGlobal: true);
        $flattened = BackofficePermissionGroups::flatten($groups, includeGlobal: true);

        $this->assertEqualsCanonicalizing($keys, $flattened);
        $this->assertSame(count($flattened), count(array_unique($flattened)));
        foreach (BackofficePermissionGroups::all() as $id => $group) {
            $this->assertSame($group, BackofficePermissionGroups::all(includeGlobal: true)[$id]);
        }
        $this->assertContains(Permission::CREATE_TEAM->value, $groups['creation']);
        $this->assertContains(Permission::EDIT_USER_PERSONAL_DATA->value, $groups['privileged']);
        $this->assertContains(Permission::CONTENT_MANAGE->value, $groups['global_other']);
    }

    public function test_role_profile_snapshot_rejects_missing_global_groups(): void
    {
        $groups = BackofficePermissionGroups::split([], includeGlobal: true);
        unset($groups['creation']);

        $this->expectException(ValidationException::class);
        BackofficePermissionGroups::flatten($groups, includeGlobal: true);
    }

    public function test_partial_permission_snapshot_cannot_silently_revoke_missing_groups(): void
    {
        $groups = BackofficePermissionGroups::split([Permission::BACKOFFICE_ACCESS->value]);
        unset($groups['users']);

        $this->expectException(ValidationException::class);
        BackofficePermissionGroups::flatten($groups);
    }

    public function test_permission_from_another_group_is_not_accepted(): void
    {
        $groups = BackofficePermissionGroups::split([]);
        $groups['general'] = [Permission::BACKOFFICE_USER_CREATE->value];

        $this->expectException(ValidationException::class);
        BackofficePermissionGroups::flatten($groups);
    }
}
