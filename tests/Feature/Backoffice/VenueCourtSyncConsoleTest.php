<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Venues\Pages\EditVenue;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\CourtsRelationManager;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class VenueCourtSyncConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_sees_manual_sync_for_one_and_all_courts_without_auto_mode(): void
    {
        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $venue = Venue::factory()->create();
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        $venue->schedule->intervals()->create([
            'day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '21:00',
            'sort_order' => 0,
        ]);

        Livewire::test(CourtsRelationManager::class, [
            'ownerRecord' => $venue,
            'pageClass' => EditVenue::class,
        ])->assertSuccessful()
            ->assertSee('Синхронизировать все залы')
            ->assertSee('Синхронизировать');

        $this->assertFalse($venue->primaryCourt()->firstOrFail()->auto_sync_enabled);
        $this->assertDatabaseMissing('venue_court_schedules', [
            'venue_court_id' => $venue->primaryCourt()->firstOrFail()->id,
        ]);
    }
}
