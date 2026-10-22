<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Pages\RestApiExplorer;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RestApiExplorerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The PHP tests precede npm build; Vite manifest isn't relevant to authorization.
        $this->withoutVite();
    }

    private function account(Role $role, UserStatusEnum $status = UserStatusEnum::CONFIRMED): User
    {
        $user = User::factory()->create(['system_role' => $role, 'status' => $status]);
        $user->createProfile([]);

        return $user;
    }

    public function test_superadmin_can_view_swagger_and_export_spec(): void
    {
        $super = $this->account(Role::SUPERADMIN);

        $this->actingAs($super)
            ->get('/backoffice/rest-api-explorer')
            ->assertOk()
            ->assertSee('mskba-swagger-ui')
            ->assertSee('REST API');

        $document = $this->get('/backoffice/rest-api/openapi.json');
        $document->assertOk()
            ->assertJsonPath('openapi', '3.1.0')
            ->assertJsonStructure(['paths' => ['/api/v1/auth/login', '/api/v1/me', '/api/v1/venues']]);

        // Symfony may reorder directives, but the response must remain private.
        $cache = (string) $document->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-store', $cache);
    }

    public function test_admin_is_denied_to_page_and_raw_spec_by_default(): void
    {
        $admin = $this->account(Role::ADMIN);
        $this->actingAs($admin);

        $this->assertFalse(RestApiExplorer::canAccess());
        $this->get('/backoffice/rest-api-explorer')->assertForbidden();
        $this->get('/backoffice/rest-api/openapi.json')->assertForbidden();
    }

    public function test_manager_permission_can_be_granted_then_revoked_without_affecting_graphiql(): void
    {
        $super = $this->account(Role::SUPERADMIN);
        $manager = $this->account(Role::MANAGER);
        $permissions = app(BackofficePermissionManager::class);

        $permissions->sync($super, $manager, [
            Permission::BACKOFFICE_ACCESS->value,
            Permission::BACKOFFICE_REST_API_EXPLORER_VIEW->value,
        ]);

        $this->actingAs($manager)->get('/backoffice/rest-api-explorer')->assertOk();
        $this->get('/backoffice/rest-api/openapi.json')->assertOk();
        $this->assertFalse($permissions->allows($manager, Permission::BACKOFFICE_GRAPHQL_EXPLORER_VIEW));

        $permissions->sync($super, $manager, [Permission::BACKOFFICE_ACCESS->value]);
        $this->flushSession();
        $this->actingAs($manager->fresh());
        $this->get('/backoffice/rest-api-explorer')->assertForbidden();
        $this->get('/backoffice/rest-api/openapi.json')->assertForbidden();
    }

    public function test_unconfirmed_superadmin_and_ordinary_users_cannot_open(): void
    {
        $unconfirmed = $this->account(Role::SUPERADMIN, UserStatusEnum::UNCONFIRMED);
        $this->actingAs($unconfirmed)->get('/backoffice/rest-api-explorer')->assertForbidden();
        $this->get('/backoffice/rest-api/openapi.json')->assertForbidden();

        $this->flushSession();
        $ordinary = $this->account(Role::USER);
        $this->actingAs($ordinary)->get('/backoffice/rest-api-explorer')->assertForbidden();
        $this->get('/backoffice/rest-api/openapi.json')->assertForbidden();
    }

    public function test_swagger_ui_is_disabled_on_production(): void
    {
        $super = $this->account(Role::SUPERADMIN);
        $this->actingAs($super);
        app()->detectEnvironment(fn (): string => 'production');

        $this->assertFalse(RestApiExplorer::canAccess());
        $this->get('/backoffice/rest-api-explorer')->assertForbidden();
        $this->get('/backoffice/rest-api/openapi.json')->assertForbidden();
    }
}
