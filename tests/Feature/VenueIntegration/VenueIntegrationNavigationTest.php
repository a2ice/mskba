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

        $this->assertContains(\App\Filament\Backoffice\Resources\Venues\RelationManagers\SpecialTermsRelationManager::class, $relations);
        $groups = collect($relations)->filter(fn ($relation): bool => $relation instanceof RelationGroup)->values();
        $this->assertSame(['Интеграции', 'Заявки', 'Контракты'], $groups->map(fn (RelationGroup $group): string => $group->getLabel())->all());
        $this->assertSame([
            \App\Filament\Backoffice\Resources\Venues\RelationManagers\ContractHistoryRelationManager::class,
            \App\Filament\Backoffice\Resources\Venues\RelationManagers\CommercialMembershipsRelationManager::class,
            \App\Filament\Backoffice\Resources\Venues\RelationManagers\AgentAgreementsRelationManager::class,
            \App\Filament\Backoffice\Resources\Venues\RelationManagers\AssignedManagersRelationManager::class,
        ], $groups->last()->getManagers());

        $group = collect($relations)->first(fn ($relation): bool => $relation instanceof RelationGroup && $relation->getLabel() === 'Интеграции');
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

        $relations = VenueResource::getRelations();
        $integrationKey = array_search(true, array_map(
            fn ($relation): bool => $relation instanceof RelationGroup && $relation->getLabel() === 'Интеграции',
            $relations,
        ), true);
        $this->assertNotFalse($integrationKey);

        $page->set('activeRelationManager', (string) $integrationKey)
            ->assertSuccessful()
            ->assertSee('Настройки')
            ->assertSee('Источники и залы')
            ->assertSee('Журнал изменений');
    }

}
