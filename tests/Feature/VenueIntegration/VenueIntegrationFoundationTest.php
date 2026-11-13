<?php

namespace Tests\Feature\VenueIntegration;

use App\Filament\Backoffice\Resources\Venues\Pages\EditVenue;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\VenueIntegrationsRelationManager;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\VenueIntegrationSourcesRelationManager;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\VenueIntegrationAuditRelationManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueIntegration\Application\Services\VenueIntegrationManager;
use App\Modules\VenueIntegration\Infrastructure\Adapters\FindSportReadOnlyAdapter;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class VenueIntegrationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $admin->createProfile([]);

        return $admin;
    }

    /** @return array<string,mixed> */
    private function settings(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Расписание площадки',
            'description' => 'Тестовые источники',
            'timezone' => 'Europe/Moscow',
            'freshness_ttl_seconds' => 900,
            'horizon_days' => 14,
            'refresh_on_visit' => false,
            'scheduled_refresh' => false,
            'mode' => 'test',
            'enabled' => false,
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function source(int $courtId, array $overrides = []): array
    {
        return array_replace([
            'name' => 'Ручная проверка слотов',
            'adapter_key' => 'manual',
            'external_id' => 'manual-venue',
            'endpoint_url' => null,
            'priority' => 100,
            'capabilities' => ['availability', 'pricing'],
            'permission_status' => 'unchecked',
            'permission_reference' => null,
            'court_ids' => [$courtId],
            'enabled' => false,
        ], $overrides);
    }

    public function test_configuration_and_sources_are_inactive_by_default_and_manual_setup_is_audited(): void
    {
        $manager = app(VenueIntegrationManager::class);
        $venue = Venue::factory()->create();
        $courtId = $venue->courts()->firstOrFail()->id;
        $actor = $this->superadmin();

        $config = $manager->saveConfiguration($venue, $actor, $this->settings());
        $this->assertFalse($config->enabled);
        $this->assertSame('test', $config->mode);
        $this->assertSame(1, $config->version);

        $source = $manager->saveSource($config, $actor, $this->source($courtId));
        $this->assertFalse($source->enabled);
        $this->assertDatabaseHas('venue_integration_court_mappings', [
            'venue_integration_source_id' => $source->id, 'venue_court_id' => $courtId,
        ]);
        $this->assertDatabaseCount('venue_integration_audit_events', 2);

        $active = $manager->saveSource($config, $actor, $this->source($courtId, [
            'enabled' => true,
        ]), $source);
        $this->assertTrue($active->enabled);

        $enabled = $manager->saveConfiguration($venue, $actor, $this->settings(['enabled' => true]));
        $this->assertTrue($enabled->enabled);
        $this->assertSame(2, $enabled->version);
        $this->assertDatabaseCount('venue_integration_audit_events', 4);
    }

    public function test_external_source_credentials_are_encrypted_and_never_in_audit_or_serialized_output(): void
    {
        $manager = app(VenueIntegrationManager::class);
        $venue = Venue::factory()->create();
        $actor = $this->superadmin();
        $config = $manager->saveConfiguration($venue, $actor, $this->settings());
        $secret = 'private-integration-token-should-not-leak';
        $source = $manager->saveSource($config, $actor, $this->source(
            $venue->courts()->firstOrFail()->id,
            [
                'name' => 'FindSport reference',
                'adapter_key' => 'api',
                'endpoint_url' => 'https://findsport.ru/playground/4805',
                'credentials' => ['token' => $secret],
                'permission_status' => 'testing_only',
                'permission_reference' => 'Внутреннее согласование для офлайн-фикстур',
            ],
        ));

        $ciphertext = (string) DB::table('venue_integration_sources')
            ->where('id', $source->id)->value('credentials');
        $this->assertNotSame('', $ciphertext);
        $this->assertStringNotContainsString($secret, $ciphertext);
        $this->assertSame(['token' => $secret], $source->credentials);
        $this->assertArrayNotHasKey('credentials', $source->toArray());
        $this->assertStringNotContainsString(
            $secret, (string) DB::table('venue_integration_audit_events')->pluck('changes')->implode('|')
        );
        $this->assertFalse($source->enabled);

        // Editing without passing another credential leaves the ciphertext intact.
        $manager->saveSource($config, $actor, $this->source(
            $venue->courts()->firstOrFail()->id,
            [
                'name' => 'FindSport reference (edited)',
                'adapter_key' => 'api',
                'endpoint_url' => 'https://findsport.ru/playground/4805',
                'permission_status' => 'testing_only',
                'permission_reference' => 'Внутреннее согласование для офлайн-фикстур',
            ],
        ), $source);
        $this->assertSame(['token' => $secret], $source->fresh()->credentials);
    }

    public function test_external_sources_cannot_be_enabled_until_adapter_kill_switch_is_explicitly_lifted(): void
    {
        $actor = $this->superadmin();
        $manager = app(VenueIntegrationManager::class);
        $venue = Venue::factory()->create();
        $config = $manager->saveConfiguration($venue, $actor, $this->settings());
        $this->expectException(ValidationException::class);
        $manager->saveSource($config, $actor, $this->source(
            $venue->courts()->firstOrFail()->id,
            [
                'adapter_key' => 'api',
                'endpoint_url' => 'https://findsport.ru/playground/4805',
                'permission_status' => 'mskba_allowed',
                'permission_reference' => 'Тестовая фикстура, без сетевых запросов',
                'enabled' => true,
            ],
        ));
    }

    public function test_private_hosts_and_wrong_venue_courts_are_rejected(): void
    {
        $actor = $this->superadmin();
        $manager = app(VenueIntegrationManager::class);
        $venue = Venue::factory()->create();
        $otherVenue = Venue::factory()->create();
        $config = $manager->saveConfiguration($venue, $actor, $this->settings());
        $courtId = $venue->courts()->firstOrFail()->id;
        foreach ([
            'http://findsport.ru/playground/4805',
            'https://localhost/slots',
            'https://127.0.0.1/slots',
            'https://192.168.1.10/api',
            'https://findsport.ru/playground/4805?token=private',
            'https://user:secret@findsport.ru/slots',
        ] as $url) {
            try {
                $manager->saveSource($config, $actor, $this->source($courtId, [
                    'adapter_key' => 'api', 'endpoint_url' => $url,
                ]));
                $this->fail('Unsafe URL accepted: '.$url);
            } catch (ValidationException) {
                // Deliberately rejected. No record is created.
            }
        }
        $this->assertDatabaseCount('venue_integration_sources', 0);

        $this->expectException(ValidationException::class);
        $manager->saveSource($config, $actor, $this->source(
            $otherVenue->courts()->firstOrFail()->id,
        ));
    }

    public function test_owner_has_no_integration_permissions_but_explicitly_delegated_backoffice_admin_does(): void
    {
        $venue = Venue::factory()->create();
        $manager = app(VenueIntegrationManager::class);
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->assertFalse($manager->can($owner, Permission::BACKOFFICE_VENUE_INTEGRATIONS_VIEW));
        $this->expectException(AuthorizationException::class);
        $manager->saveConfiguration($venue, $owner, $this->settings());
    }

    public function test_delegated_access_needs_all_three_independent_backoffice_permissions(): void
    {
        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::ADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $admin->createProfile([]);
        $manager = app(VenueIntegrationManager::class);
        $venue = Venue::factory()->create();
        $this->assertFalse($manager->can($admin, Permission::BACKOFFICE_VENUE_INTEGRATIONS_MANAGE));

        foreach ([
            Permission::BACKOFFICE_VENUE_MANAGE,
            Permission::BACKOFFICE_VENUE_INTEGRATIONS_VIEW,
            Permission::BACKOFFICE_VENUE_INTEGRATIONS_MANAGE,
        ] as $permission) {
            UserOperationalPermission::query()->create([
                'user_id' => $admin->id,
                'permission' => $permission->value,
                'is_allowed' => true,
            ]);
        }
        $this->assertTrue($manager->can($admin, Permission::BACKOFFICE_VENUE_INTEGRATIONS_MANAGE));
        $manager->saveConfiguration($venue, $admin, $this->settings());
        $this->assertDatabaseCount('venue_integrations', 1);
        $this->assertFalse($manager->can($admin, Permission::BACKOFFICE_VENUE_INTEGRATIONS_AUDIT));
        $this->assertFalse($manager->can($admin, Permission::BACKOFFICE_VENUE_INTEGRATIONS_EXECUTE));
    }

    public function test_findsport_preview_reports_missing_access_basis_without_network_request(): void
    {
        config()->set('venue_integrations.findsport_preview_enabled', true);
        $actor = $this->superadmin();
        $manager = app(VenueIntegrationManager::class);
        $venue = Venue::factory()->create();
        $config = $manager->saveConfiguration($venue, $actor, $this->settings());
        $source = $manager->saveSource($config, $actor, $this->source(
            $venue->courts()->firstOrFail()->id,
            [
                'adapter_key' => 'api',
                'endpoint_url' => 'https://findsport.ru/playground/4805',
                'permission_status' => 'testing_only',
                'permission_reference' => null,
            ],
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('не заполнено поле «Основание допустимого доступа»');
        app(FindSportReadOnlyAdapter::class)->preview($source, $actor);
    }

    public function test_findsport_preview_reports_missing_court_binding_without_network_request(): void
    {
        config()->set('venue_integrations.findsport_preview_enabled', true);
        $actor = $this->superadmin();
        $manager = app(VenueIntegrationManager::class);
        $venue = Venue::factory()->create();
        $config = $manager->saveConfiguration($venue, $actor, $this->settings());
        $source = $manager->saveSource($config, $actor, $this->source(
            $venue->courts()->firstOrFail()->id,
            [
                'adapter_key' => 'api',
                'endpoint_url' => 'https://findsport.ru/playground/4805',
                'permission_status' => 'testing_only',
                'permission_reference' => 'Подтверждённый тестовый доступ',
            ],
        ));
        $this->assertNotNull($source->access_reviewed_at);
        $source->courtMappings()->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Сейчас привязано: 0');
        app(FindSportReadOnlyAdapter::class)->preview($source->refresh(), $actor);
    }

    public function test_findsport_review_can_be_reconfirmed_when_old_timestamp_is_missing(): void
    {
        $actor = $this->superadmin();
        $manager = app(VenueIntegrationManager::class);
        $venue = Venue::factory()->create();
        $config = $manager->saveConfiguration($venue, $actor, $this->settings());
        $courtId = $venue->courts()->firstOrFail()->id;
        $data = $this->source($courtId, [
            'adapter_key' => 'api',
            'endpoint_url' => 'https://findsport.ru/playground/4805',
            'permission_status' => 'testing_only',
            'permission_reference' => 'Проверенное основание',
        ]);
        $source = $manager->saveSource($config, $actor, $data);
        $source->update(['access_reviewed_at' => null]);
        $this->assertNull($source->refresh()->access_reviewed_at);

        $updated = $manager->saveSource($config, $actor, $data, $source);
        $this->assertNotNull($updated->access_reviewed_at);
    }

    public function test_filament_has_native_settings_sources_and_read_only_audit_tabs(): void
    {
        $actor = $this->superadmin();
        $venue = Venue::factory()->create();
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $this->assertTrue(VenueIntegrationsRelationManager::canViewForRecord($venue, EditVenue::class));
        $this->assertTrue(VenueIntegrationSourcesRelationManager::canViewForRecord($venue, EditVenue::class));
        $this->assertTrue(VenueIntegrationAuditRelationManager::canViewForRecord($venue, EditVenue::class));

        Livewire::test(VenueIntegrationsRelationManager::class, [
            'ownerRecord' => $venue, 'pageClass' => EditVenue::class,
        ])->assertSuccessful()
            ->assertSee('Интеграции не настроены')
            ->assertSee('Настроить интеграцию');

        Livewire::test(VenueIntegrationSourcesRelationManager::class, [
            'ownerRecord' => $venue, 'pageClass' => EditVenue::class,
        ])->assertSuccessful()->assertSee('Источников нет');

        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner);
        $this->assertFalse(VenueIntegrationsRelationManager::canViewForRecord($venue, EditVenue::class));
        $this->assertFalse(VenueIntegrationSourcesRelationManager::canViewForRecord($venue, EditVenue::class));
        $this->assertFalse(VenueIntegrationAuditRelationManager::canViewForRecord($venue, EditVenue::class));
    }
}
