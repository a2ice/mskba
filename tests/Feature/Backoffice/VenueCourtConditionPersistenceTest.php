<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Venues\Pages\EditVenue;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\CourtsRelationManager;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class VenueCourtConditionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_edit_keeps_explicit_no_values_for_primary_court_and_yes_for_secondary(): void
    {
        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $venue = Venue::factory()->create([
            'name' => 'Школа №1794',
            'requires_payment' => true,
            'requires_booking' => true,
            'requires_booking_approval' => true,
        ]);
        $outdoor = $venue->primaryCourt()->firstOrFail();
        $indoor = $venue->courts()->create([
            'name' => 'Школьный зал',
            'alias' => 'indoor',
            'environment' => 'indoor',
            'sort_order' => 20,
        ]);

        Livewire::test(CourtsRelationManager::class, [
            'ownerRecord' => $venue,
            'pageClass' => EditVenue::class,
        ])->callAction(TestAction::make('edit')->table($outdoor), data: [
            'requires_payment' => 'no',
            'requires_booking' => 'no',
            'requires_booking_approval' => 'no',
            'rental_enabled' => 'no',
            'operational_status' => 'active',
        ])->assertHasNoActionErrors();

        $outdoor->refresh();
        $this->assertFalse($outdoor->requires_payment);
        $this->assertFalse($outdoor->requires_booking);
        $this->assertFalse($outdoor->requires_booking_approval);
        $this->assertFalse($outdoor->rental_enabled);
        $this->assertSame('active', $outdoor->operational_status->value);

        Livewire::test(CourtsRelationManager::class, [
            'ownerRecord' => $venue,
            'pageClass' => EditVenue::class,
        ])->callAction(TestAction::make('edit')->table($indoor), data: [
            'requires_payment' => 'yes',
            'requires_booking' => 'yes',
            'requires_booking_approval' => 'yes',
            'rental_enabled' => 'yes',
            'operational_status' => 'inherit',
        ])->assertHasNoActionErrors();

        $indoor->refresh();
        $this->assertTrue($indoor->requires_payment);
        $this->assertTrue($indoor->requires_booking);
        $this->assertTrue($indoor->requires_booking_approval);
        $this->assertTrue($indoor->rental_enabled);
        $this->assertNull($indoor->operational_status);
        $this->assertFalse($outdoor->fresh()->requires_payment);
    }
}
