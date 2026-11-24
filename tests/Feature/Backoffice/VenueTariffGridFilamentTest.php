<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Venues\Pages\EditVenueTariffs;
use App\Filament\Backoffice\Resources\Venues\Pages\EditVenue;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\CourtsRelationManager;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Filament\Backoffice\Resources\Venues\Support\VenueTariffModal;
use Filament\Actions\Testing\TestAction;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\PublishVenueBookingPolicyHandler;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class VenueTariffGridFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
    }

    public function test_real_filament_schedule_popup_saves_venue_price_bands_and_reloads_them(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $venue = Venue::factory()->create(['requires_payment' => true]);
        $court = $venue->primaryCourt()->firstOrFail();
        $policy = app(PublishVenueBookingPolicyHandler::class)->handle($venue, $admin, [
            'is_enabled' => true, 'allows_whole' => true, 'allows_halves' => false,
            'minimum_duration_minutes' => 60, 'maximum_duration_minutes' => 180,
            'time_step_minutes' => 30, 'minimum_lead_time_minutes' => 120,
            'maximum_advance_days' => 90, 'currency' => 'RUB',
            'whole_price_per_step_minor' => 50000, 'half_price_per_step_minor' => null,
            'hold_duration_minutes' => 30, 'requires_payment' => true,
            'payment_window_minutes' => 30, 'quote_validity_minutes' => 15,
            'cancellation_before_minutes' => 1440,
        ]);

        $band = [
            'days' => [1, 2],
            'starts_at' => '07:00', 'ends_at' => '16:00',
            'operation' => 'set', 'whole_price_per_step' => '1000',
            'half_price_per_step' => null, 'exceptions' => [],
        ];
        Livewire::test(EditVenue::class, ['record' => $venue->id])
            ->callAction(
                TestAction::make('venueTariffsFromSchedule')->schemaComponent(),
                data: ['mode' => 'weekly', 'ranges' => [$band]],
            )
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('venue_schedule_slot_prices', [
            'venue_id' => $venue->id, 'day_of_week' => 1,
            'starts_at' => '07:00:00', 'whole_price_per_step_minor' => 100000,
        ]);
        $state = VenueTariffModal::initialState($venue, null);
        $this->assertSame('07:00', $state['ranges'][0]['starts_at']);
        $this->assertSame([1, 2], $state['ranges'][0]['days']);
        $this->assertSame('1000', $state['ranges'][0]['whole_price_per_step']);

        $courtBand = [
            'days' => [1],
            'starts_at' => '16:00', 'ends_at' => '23:30',
            'operation' => 'set', 'whole_price_per_step' => '1500',
            'half_price_per_step' => null, 'exceptions' => [],
        ];
        Livewire::test(CourtsRelationManager::class, [
            'ownerRecord' => $venue,
            'pageClass' => EditVenue::class,
        ])
            ->callAction(
                TestAction::make('unifiedTariffGrid')->table($court),
                data: ['mode' => 'weekly', 'ranges' => [$courtBand]],
            )
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('venue_court_manual_slot_prices', [
            'venue_court_id' => $court->id, 'day_of_week' => 1,
            'starts_at' => '16:00:00', 'whole_price_per_step_minor' => 150000,
        ]);
        $courtState = VenueTariffModal::initialState($venue, $court);
        $this->assertSame('16:00', $courtState['ranges'][0]['starts_at']);
        $this->assertSame('1500', $courtState['ranges'][0]['whole_price_per_step']);
    }

    public function test_venue_schedule_and_court_table_offer_native_tariff_popups_instead_of_external_links(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $venue = Venue::factory()->create(['requires_payment' => true]);
        $this->get(VenueResource::getUrl('edit', ['record' => $venue]))
            ->assertOk()
            ->assertSee('Тарифы площадки по времени')
            ->assertSee('Тарифная сетка площадки');

        Livewire::test(CourtsRelationManager::class, [
            'ownerRecord' => $venue,
            'pageClass' => EditVenue::class,
        ])->assertSuccessful()->assertSee('Тарифная сетка');
    }

    public function test_filament_has_its_own_tariff_page_and_saves_two_ranges_without_public_routes(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $venue = Venue::factory()->create(['requires_payment' => true]);
        $court = $venue->primaryCourt()->firstOrFail();
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $admin, [
            'is_enabled' => true, 'allows_whole' => true, 'allows_halves' => false,
            'minimum_duration_minutes' => 60, 'maximum_duration_minutes' => 180,
            'time_step_minutes' => 30, 'minimum_lead_time_minutes' => 120,
            'maximum_advance_days' => 90, 'currency' => 'RUB',
            'whole_price_per_step_minor' => 50000, 'half_price_per_step_minor' => null,
            'hold_duration_minutes' => 30, 'requires_payment' => true,
            'payment_window_minutes' => 30, 'quote_validity_minutes' => 15,
            'cancellation_before_minutes' => 1440,
        ]);

        $url = VenueResource::getUrl('tariffs', ['record' => $venue]);
        $this->assertStringContainsString('/tariffs', $url);
        $this->assertStringNotContainsString('/account/venues/', $url);
        $this->get($url)->assertOk()
            ->assertSee('Добавить диапазон')
            ->assertSee('Сохранить все диапазоны')
            ->assertSee('Действующие тарифы и источник');

        Livewire::test(EditVenueTariffs::class, ['record' => $venue->id])
            ->set('courtId', $court->id)
            ->set('ranges', [
                [
                    'days' => [1, 2],
                    'starts_at' => '07:00', 'ends_at' => '16:00',
                    'operation' => 'set', 'whole_price_per_step' => '1000',
                    'half_price_per_step' => '',
                    'exceptions' => [],
                ],
                [
                    'days' => [1, 2],
                    'starts_at' => '16:00', 'ends_at' => '23:30',
                    'operation' => 'set', 'whole_price_per_step' => '1500',
                    'half_price_per_step' => '',
                    'exceptions' => [
                        [
                            'starts_at' => '18:00', 'day_of_week' => 2,
                            'operation' => 'set', 'whole_price_per_step' => '2000',
                            'half_price_per_step' => '',
                        ],
                    ],
                ],
            ])
            ->call('saveRanges')
            ->assertHasNoErrors();

        $this->assertSame(66, DB::table('venue_court_manual_slot_prices')
            ->where('venue_court_id', $court->id)->count());
        $this->assertDatabaseHas('venue_court_manual_slot_prices', [
            'venue_court_id' => $court->id, 'day_of_week' => 2, 'starts_at' => '18:00:00',
            'whole_price_per_step_minor' => 200000,
        ]);
    }

    public function test_unprivileged_user_cannot_open_filament_tariff_editor(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        $admin->createProfile([]);
        $venue = Venue::factory()->create();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->get(VenueResource::getUrl('tariffs', ['record' => $venue]))
            ->assertForbidden();
    }
}
