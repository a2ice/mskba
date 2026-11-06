<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Venues\Pages\EditVenue;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\CommissionAgentsRelationManager;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\CourtCommissionsRelationManager;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class VenueCommissionConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_open_portal_venue_and_agent_settings_without_booking_feature(): void
    {
        config()->set('features.venue_rental.rental_flow', false);
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $venue = Venue::factory()->create();

        Livewire::test(CommissionAgentsRelationManager::class, [
            'ownerRecord' => $venue, 'pageClass' => EditVenue::class,
        ])->assertSuccessful()
            ->assertSee('Коммерческие агенты ещё не назначены')
            ->assertSee('Комиссия MSKBA')
            ->assertSee('Добавить агента')
            ->mountAction(TestAction::make('portalCommission')->table())
            ->assertMountedActionModalSee('Глобальная комиссия MSKBA');

        Livewire::test(CommissionAgentsRelationManager::class, [
            'ownerRecord' => $venue, 'pageClass' => EditVenue::class,
        ])->mountAction(TestAction::make('venueCommission')->table())
            ->assertMountedActionModalSee('Комиссия площадки');

        Livewire::test(CommissionAgentsRelationManager::class, [
            'ownerRecord' => $venue, 'pageClass' => EditVenue::class,
        ])->mountAction(TestAction::make('createCommissionAgent')->table())
            ->assertMountedActionModalSee('Новый коммерческий агент');
    }

    public function test_court_commission_editor_opens_and_is_not_visible_to_non_superadmin(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $venue = Venue::factory()->create();

        Livewire::test(CourtCommissionsRelationManager::class, [
            'ownerRecord' => $venue, 'pageClass' => EditVenue::class,
        ])->assertSuccessful()
            ->assertSee('Переопределений для залов пока нет')
            ->mountAction(TestAction::make('setCourtCommission')->table())
            ->assertMountedActionModalSee('Комиссия MSKBA для зала');

        $ordinary = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($ordinary);
        $this->assertFalse(CommissionAgentsRelationManager::canViewForRecord($venue, EditVenue::class));
        $this->assertFalse(CourtCommissionsRelationManager::canViewForRecord($venue, EditVenue::class));
    }
}
