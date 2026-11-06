<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Venues\Pages\CreateVenue;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class VenueCreateFilamentFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_create_button_creates_a_venue_with_a_primary_court(): void
    {
        $user = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(CreateVenue::class)
            ->fillForm([
                'name' => 'Тестовая площадка Livewire',
                'type' => VenueTypeEnum::STREET_COURT->value,
                'status' => VenueStatusEnum::UNCONFIRMED->value,
                'owner_user_id' => $user->id,
                'operational_status' => 'active',
                'access_type' => 'free',
                'schedule_timezone' => 'Europe/Moscow',
                'initial_courts' => [[
                    'name' => 'Корт 1',
                    'environment' => 'outdoor',
                    'hoops_count' => 2,
                    'amenity_ids' => [],
                ]],
                'weekly_default_intervals' => [
                    ['starts_at' => '10:00', 'ends_at' => '22:00'],
                ],
                'weekday_overrides' => [
                    ['day_of_week' => 7, 'is_closed' => true, 'intervals' => []],
                ],
                'schedule_exceptions' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $venue = Venue::query()->where('name', 'Тестовая площадка Livewire')->firstOrFail();
        $this->assertSame(1, $venue->courts()->count());
        $this->assertSame('Корт 1', $venue->primaryCourt()->firstOrFail()->name);
        $this->assertSame('outdoor', $venue->primaryCourt()->firstOrFail()->environment->value);
        $this->assertTrue($venue->primaryCourt()->exists());
        $this->assertSame(6, $venue->schedule()->firstOrFail()->intervals()->count());
    }
}
