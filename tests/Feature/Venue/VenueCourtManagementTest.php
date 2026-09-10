<?php

namespace Tests\Feature\Venue;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\VenueBooking;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueSurfaceTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueCourtManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_venue_has_one_primary_default_court(): void
    {
        $venue = Venue::factory()->create();

        $this->assertDatabaseHas('venue_courts', [
            'venue_id' => $venue->id,
            'name' => 'Зал 1',
            'alias' => 'zal-1',
            'is_primary' => true,
        ]);
        $this->assertSame(1, $venue->courts()->count());
    }

    public function test_owner_can_add_edit_and_switch_primary_court(): void
    {
        $owner = User::factory()->create();
        $venue = $this->venueFor($owner);
        $firstCourt = $venue->courts()->firstOrFail();

        $this->actingAs($owner)
            ->post(route('account.venues.courts.store', $venue->routeIdentifier()), [
                'name' => 'Малый зал',
                'hoops_count' => 2,
                'surface_type' => VenueSurfaceTypeEnum::PARQUET->value,
                'allows_whole' => '1',
                'allows_halves' => '1',
            ])
            ->assertRedirect(route('account.venues.courts.index', $venue->routeIdentifier()));

        $secondCourt = $venue->courts()->where('name', 'Малый зал')->firstOrFail();
        $this->assertSame('malyi-zal', $secondCourt->alias);
        $this->assertTrue($secondCourt->supports_halves);
        $this->assertSame(2, $secondCourt->hoops_count);
        $this->assertSame(VenueSurfaceTypeEnum::PARQUET, $secondCourt->surface_type);
        $this->assertTrue($secondCourt->allows_whole);
        $this->assertTrue($secondCourt->allows_halves);
        $this->assertFalse($secondCourt->is_primary);

        $this->actingAs($owner)
            ->put(route('account.venues.courts.update', [$venue->routeIdentifier(), $secondCourt->routeIdentifier()]), [
                'name' => 'Зал Молния',
                'alias' => 'molniya',
                'sort_order' => 20,
                'hoops_count' => 1,
                'surface_type' => VenueSurfaceTypeEnum::ASPHALT->value,
                'allows_whole' => '1',
                'allows_halves' => '1',
            ])
            ->assertRedirect(route('account.venues.courts.index', $venue->routeIdentifier()));

        $this->assertDatabaseHas('venue_courts', [
            'id' => $secondCourt->id,
            'name' => 'Зал Молния',
            'alias' => 'molniya',
            'hoops_count' => 1,
            'surface_type' => VenueSurfaceTypeEnum::ASPHALT->value,
            'supports_halves' => false,
            'allows_whole' => true,
            'allows_halves' => false,
        ]);

        $this->actingAs($owner)
            ->post(route('account.venues.courts.primary', [$venue->routeIdentifier(), $secondCourt->fresh()->routeIdentifier()]))
            ->assertRedirect(route('account.venues.courts.index', $venue->routeIdentifier()));

        $this->assertFalse($firstCourt->fresh()->is_primary);
        $this->assertTrue($secondCourt->fresh()->is_primary);
    }

    public function test_new_court_inherits_parent_hoops_and_booking_permissions(): void
    {
        $owner = User::factory()->create();
        $venue = $this->venueFor($owner);
        $venue->characteristics()->create(['hoops_count' => 2]);
        $this->publishPolicy($venue, $owner, true, true);

        $this->actingAs($owner)
            ->post(route('account.venues.courts.store', $venue->routeIdentifier()), [
                'name' => 'Наследуемый зал',
            ])
            ->assertSessionHasNoErrors();

        $court = $venue->courts()->where('name', 'Наследуемый зал')->firstOrFail();
        $this->assertSame(2, $court->hoops_count);
        $this->assertTrue($court->supports_halves);
        $this->assertTrue($court->allows_whole);
        $this->assertTrue($court->allows_halves);
    }

    public function test_future_half_booking_blocks_reducing_court_to_one_hoop(): void
    {
        $owner = User::factory()->create();
        $venue = $this->venueFor($owner);
        $court = VenueCourt::query()->create([
            'venue_id' => $venue->id,
            'name' => 'Делимый зал',
            'alias' => 'split-court',
            'sort_order' => 20,
            'is_primary' => false,
            'hoops_count' => 2,
            'supports_halves' => true,
            'allows_whole' => true,
            'allows_halves' => true,
        ]);
        $actor = app(CurrentActorResolver::class)->resolve($owner, null);
        VenueBooking::query()->create([
            'venue_id' => $venue->id,
            'venue_court_id' => $court->id,
            'created_by_actor_id' => $actor->id,
            'status' => VenueBookingStatusEnum::CONFIRMED,
            'scope' => VenueBookingScopeEnum::HALF_A,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);

        $this->actingAs($owner)
            ->put(route('account.venues.courts.update', [$venue->routeIdentifier(), $court->routeIdentifier()]), [
                'name' => $court->name,
                'alias' => $court->alias,
                'sort_order' => $court->sort_order,
                'hoops_count' => 1,
                'allows_whole' => '1',
                'allows_halves' => '0',
            ])
            ->assertSessionHasErrors('hoops_count');

        $court->refresh();
        $this->assertSame(2, $court->hoops_count);
        $this->assertTrue($court->supports_halves);
        $this->assertTrue($court->allows_halves);
    }

    public function test_aliases_are_unique_inside_venue_and_can_repeat_between_venues(): void
    {
        $owner = User::factory()->create();
        $venue = $this->venueFor($owner);
        $otherVenue = $this->venueFor($owner);

        $this->actingAs($owner)->post(route('account.venues.courts.store', $venue->routeIdentifier()), [
            'name' => 'Малый зал',
            'alias' => 'small',
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('account.venues.courts.store', $venue->routeIdentifier()), [
            'name' => 'Ещё один',
            'alias' => 'small',
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('account.venues.courts.store', $otherVenue->routeIdentifier()), [
            'name' => 'Малый зал',
            'alias' => 'small',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('venue_courts', ['venue_id' => $venue->id, 'alias' => 'small']);
        $this->assertDatabaseHas('venue_courts', ['venue_id' => $venue->id, 'alias' => 'small-2']);
        $this->assertDatabaseHas('venue_courts', ['venue_id' => $otherVenue->id, 'alias' => 'small']);
    }

    public function test_last_court_cannot_be_deleted_and_primary_is_reassigned(): void
    {
        $owner = User::factory()->create();
        $venue = $this->venueFor($owner);
        $firstCourt = $venue->courts()->firstOrFail();

        $this->actingAs($owner)
            ->delete(route('account.venues.courts.destroy', [$venue->routeIdentifier(), $firstCourt->routeIdentifier()]))
            ->assertSessionHas('error', 'У площадки должен оставаться хотя бы один зал.');
        $this->assertNotNull($firstCourt->fresh());

        $secondCourt = VenueCourt::query()->create([
            'venue_id' => $venue->id,
            'name' => 'Зал 2',
            'alias' => 'zal-2',
            'sort_order' => 20,
            'is_primary' => false,
            'supports_halves' => false,
        ]);

        $this->actingAs($owner)
            ->delete(route('account.venues.courts.destroy', [$venue->routeIdentifier(), $firstCourt->routeIdentifier()]))
            ->assertSessionHas('status', 'Зал удалён.');

        $this->assertSoftDeleted('venue_courts', ['id' => $firstCourt->id]);
        $this->assertTrue($secondCourt->fresh()->is_primary);
    }

    public function test_referenced_court_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $venue = $this->venueFor($owner);
        $firstCourt = $venue->courts()->firstOrFail();
        VenueCourt::query()->create([
            'venue_id' => $venue->id,
            'name' => 'Зал 2',
            'alias' => 'zal-2',
            'sort_order' => 20,
            'is_primary' => false,
            'supports_halves' => false,
        ]);
        Event::factory()->create([
            'venue_id' => $venue->id,
            'venue_court_id' => $firstCourt->id,
        ]);

        $this->actingAs($owner)
            ->delete(route('account.venues.courts.destroy', [$venue->routeIdentifier(), $firstCourt->routeIdentifier()]))
            ->assertSessionHas('error', 'Нельзя удалить зал, пока с ним связаны бронирования или мероприятия.');

        $this->assertDatabaseHas('venue_courts', [
            'id' => $firstCourt->id,
            'deleted_at' => null,
        ]);
    }

    public function test_other_user_cannot_manage_courts(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $venue = $this->venueFor($owner);

        $this->actingAs($intruder)
            ->get(route('account.venues.courts.index', $venue->routeIdentifier()))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->post(route('account.venues.courts.store', $venue->routeIdentifier()), ['name' => 'Чужой зал'])
            ->assertForbidden();
    }

    private function venueFor(User $user): Venue
    {
        return Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($user, null)->id,
        ]);
    }

    private function publishPolicy(Venue $venue, User $publisher, bool $allowsWhole, bool $allowsHalves): void
    {
        VenueBookingPolicy::query()->create([
            'venue_id' => $venue->id,
            'version' => 1,
            'is_enabled' => true,
            'allows_whole' => $allowsWhole,
            'allows_halves' => $allowsHalves,
            'minimum_duration_minutes' => 60,
            'maximum_duration_minutes' => 240,
            'time_step_minutes' => 30,
            'minimum_lead_time_minutes' => 120,
            'maximum_advance_days' => 90,
            'currency' => 'RUB',
            'whole_price_per_step_minor' => 100000,
            'half_price_per_step_minor' => 60000,
            'hold_duration_minutes' => 30,
            'allows_hold_extension' => false,
            'maximum_hold_extension_minutes' => null,
            'requires_payment' => false,
            'payment_window_minutes' => null,
            'quote_validity_minutes' => 15,
            'cancellation_before_minutes' => 1440,
            'published_by_user_id' => $publisher->id,
            'published_at' => now(),
            'active_marker' => true,
        ]);
    }
}
