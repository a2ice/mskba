<?php

namespace Tests\Feature\VenueIntegration;

use App\Filament\Backoffice\Resources\Venues\Pages\EditVenue;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\VenueIntegrationAuditRelationManager;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\VenueIntegrationSourcesRelationManager;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\VenueIntegrationsRelationManager;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class VenueIntegrationNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_relation_managers_are_one_first_level_filament_tab(): void
    {
        $relations = VenueResource::getRelations();

        $this->assertCount(6, $relations);
        $this->assertCount(1, array_filter($relations, fn ($relation): bool => $relation instanceof RelationGroup));

        $group = $relations[2];
        $this->assertInstanceOf(RelationGroup::class, $group);
        $this->assertSame('Интеграции', $group->getLabel());
        $this->assertSame([
            VenueIntegrationsRelationManager::class,
            VenueIntegrationSourcesRelationManager::class,
            VenueIntegrationAuditRelationManager::class,
        ], $group->getManagers());
    }

    public function test_edit_page_renders_integration_subtabs_without_exposing_three_top_level_tabs(): void
    {
        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $admin->createProfile([]);
        $venue = Venue::factory()->create();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $page = Livewire::test(EditVenue::class, ['record' => $venue->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Интеграции');

        $page->set('activeRelationManager', '2')
            ->assertSuccessful()
            ->assertSee('Настройки')
            ->assertSee('Источники и залы')
            ->assertSee('Журнал изменений');
    }

    public function test_guest_cannot_access_the_integration_edit_page(): void
    {
        $venue = Venue::factory()->create();

        $this->get(VenueResource::getUrl('edit', ['record' => $venue]))->assertRedirect();
    }
}
