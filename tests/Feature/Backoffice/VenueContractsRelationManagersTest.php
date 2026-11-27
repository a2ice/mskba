<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Venues\Pages\EditVenue;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\AgentAgreementsRelationManager;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\AssignedManagersRelationManager;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\ContractHistoryRelationManager;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Facades\Filament;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class VenueContractsRelationManagersTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_contract_managers_open_and_actions_are_scoped(): void
    {
        $admin = User::factory()->create(['status'=>UserStatusEnum::CONFIRMED, 'system_role'=>Role::SUPERADMIN]);
        $admin->createProfile([]);
        $manager = User::factory()->create(['status'=>UserStatusEnum::CONFIRMED, 'system_role'=>Role::MANAGER]);
        $venue = Venue::factory()->create();
        $other = Venue::factory()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $params = ['ownerRecord'=>$venue,'pageClass'=>EditVenue::class];
        Livewire::test(AgentAgreementsRelationManager::class, $params)->assertSuccessful()
            ->assertSee('Предложить договор')->mountAction(TestAction::make('proposeAgreement')->table())
            ->assertMountedActionModalSee('Условия договора');
        Livewire::test(ContractHistoryRelationManager::class, $params)->assertSuccessful();
        Livewire::test(AssignedManagersRelationManager::class, $params)->assertSuccessful()
            ->callAction(TestAction::make('assignManager')->table(), data:['user_id'=>$manager->id])
            ->assertHasNoActionErrors();
        $this->assertDatabaseHas('venue_assigned_managers',['venue_id'=>$venue->id,'user_id'=>$manager->id,'active_marker'=>true]);
        $this->assertDatabaseMissing('venue_assigned_managers',['venue_id'=>$other->id]);
        $this->actingAs($manager);
        foreach ([AgentAgreementsRelationManager::class, AssignedManagersRelationManager::class, ContractHistoryRelationManager::class] as $class) {
            $this->assertFalse($class::canViewForRecord($venue, EditVenue::class));
        }
    }
}
