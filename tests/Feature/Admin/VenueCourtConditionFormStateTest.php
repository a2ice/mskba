<?php

namespace Tests\Feature\Admin;

use App\Filament\Backoffice\Resources\Venues\Support\VenueCourtConditionFormState;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueCourtConditionFormStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_free_and_open_conditions_persist_on_primary_court(): void
    {
        $venue = Venue::factory()->create([
            'requires_payment' => true,
            'requires_booking' => true,
            'requires_booking_approval' => true,
        ]);
        $primary = $venue->primaryCourt()->firstOrFail();

        $this->assertTrue($primary->is_primary);
        $this->assertSame('inherit', VenueCourtConditionFormState::forForm([
            'requires_payment' => $primary->requires_payment,
        ])['requires_payment']);

        $selection = [
            'requires_payment' => 'no',
            'requires_booking' => 'no',
            'requires_booking_approval' => 'no',
            'rental_enabled' => 'no',
            'operational_status' => 'active',
        ];
        $primary->update(VenueCourtConditionFormState::forDatabase($selection));
        $primary->refresh();

        $this->assertFalse($primary->requires_payment);
        $this->assertFalse($primary->requires_booking);
        $this->assertFalse($primary->requires_booking_approval);
        $this->assertFalse($primary->rental_enabled);
        $this->assertSame(VenueOperationalStatusEnum::ACTIVE, $primary->operational_status);
        $this->assertSame($selection, VenueCourtConditionFormState::forForm(
            $primary->only(array_keys($selection)),
        ));
    }

    public function test_secondary_court_and_inheritance_can_be_saved_independently(): void
    {
        $venue = Venue::factory()->create();
        $primary = $venue->primaryCourt()->firstOrFail();
        $indoor = $venue->courts()->create([
            'name' => 'Школьный зал',
            'alias' => 'indoor',
            'sort_order' => 20,
        ]);

        $indoor->update(VenueCourtConditionFormState::forDatabase([
            'requires_payment' => 'yes',
            'requires_booking' => 'yes',
            'requires_booking_approval' => 'yes',
            'rental_enabled' => 'yes',
            'operational_status' => 'inherit',
        ]));
        $this->assertNull($indoor->fresh()->operational_status);
        $this->assertTrue($indoor->fresh()->requires_payment);
        $this->assertNull($primary->fresh()->requires_payment);

        $indoor->update(VenueCourtConditionFormState::forDatabase([
            'requires_payment' => 'inherit',
            'requires_booking' => 'inherit',
            'requires_booking_approval' => 'inherit',
            'rental_enabled' => 'inherit',
            'operational_status' => 'inherit',
        ]));

        $indoor->refresh();
        $this->assertNull($indoor->requires_payment);
        $this->assertNull($indoor->requires_booking);
        $this->assertNull($indoor->requires_booking_approval);
        $this->assertNull($indoor->rental_enabled);
    }
}
