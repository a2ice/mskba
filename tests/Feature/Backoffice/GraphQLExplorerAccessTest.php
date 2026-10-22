<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Pages\GraphQLExplorer;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GraphQLExplorerAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role, UserStatusEnum $status = UserStatusEnum::CONFIRMED): User
    {
        $user = User::factory()->create([
            'system_role' => $role,
            'status' => $status,
        ]);
        $user->createProfile([]);

        return $user;
    }

    public function test_only_superadmin_has_default_access(): void
    {
        $superadmin = $this->user(Role::SUPERADMIN);
        $admin = $this->user(Role::ADMIN);

        $this->assertContains(
            Permission::BACKOFFICE_GRAPHQL_EXPLORER_VIEW,
            Permission::backofficeCases(),
        );

        $this->actingAs($admin);
        $this->assertFalse(GraphQLExplorer::canAccess());
        $this->get('/backoffice/graphql-explorer')->assertForbidden();

        $this->flushSession();
        $this->actingAs($superadmin);
        $this->assertTrue(GraphQLExplorer::canAccess());
        $this->get('/backoffice/graphql-explorer')
            ->assertOk()
            ->assertSee('mskba-graphiql-root')
            ->assertSee('/api/graphql');
    }

    public function test_superadmin_can_grant_and_revoke_access_to_manager(): void
    {
        $superadmin = $this->user(Role::SUPERADMIN);
        $manager = $this->user(Role::MANAGER);
        $access = app(BackofficePermissionManager::class);

        $this->actingAs($manager);
        $this->get('/backoffice/graphql-explorer')->assertForbidden();

        $access->sync($superadmin, $manager, [
            Permission::BACKOFFICE_ACCESS->value,
            Permission::BACKOFFICE_GRAPHQL_EXPLORER_VIEW->value,
        ]);

        $this->flushSession();
        $this->actingAs($manager->fresh());
        $this->assertTrue(GraphQLExplorer::canAccess());
        $this->get('/backoffice/graphql-explorer')->assertOk();

        $access->sync($superadmin, $manager, [
            Permission::BACKOFFICE_ACCESS->value,
        ]);
        $this->flushSession();
        $this->actingAs($manager->fresh());
        $this->assertFalse(GraphQLExplorer::canAccess());
        $this->get('/backoffice/graphql-explorer')->assertForbidden();
    }

    public function test_ordinary_user_cannot_open_explorer_even_if_given_crafted_permission(): void
    {
        $user = $this->user(Role::USER);
        $this->actingAs($user);
        $this->assertFalse(GraphQLExplorer::canAccess());
        $this->get('/backoffice/graphql-explorer')->assertForbidden();
    }

    public function test_unconfirmed_superadmin_cannot_open_explorer(): void
    {
        $superadmin = $this->user(Role::SUPERADMIN, UserStatusEnum::UNCONFIRMED);
        $this->actingAs($superadmin);
        $this->assertFalse(GraphQLExplorer::canAccess());
        $this->get('/backoffice/graphql-explorer')->assertForbidden();
    }
}
