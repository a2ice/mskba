<?php

namespace Tests\Feature\Venue;

use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicVenuesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_endpoint_lists_only_confirmed_non_deleted_venues(): void
    {
        $visible = Venue::factory()->create([
            'name' => 'Visible Court',
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => VenueOperationalStatusEnum::TEMPORARILY_CLOSED->value,
        ]);
        Venue::factory()->create(['status' => VenueStatusEnum::UNCONFIRMED->value]);
        Venue::factory()->create(['status' => VenueStatusEnum::BLOCKED->value]);
        $deleted = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $deleted->delete();

        $this->getJson(route('api.v1.venues.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.status', 'confirmed')
            ->assertJsonPath('data.0.operational_status', 'temporarily_closed')
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_cursor_pagination_exhausts_all_items_without_duplicates(): void
    {
        $venues = collect(range(1, 5))->map(
            fn (int $n): Venue => Venue::factory()->create([
                'name' => "Court {$n}",
                'status' => VenueStatusEnum::CONFIRMED->value,
            ]),
        );

        $first = $this->getJson(route('api.v1.venues.index', ['limit' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.limit', 2);

        $cursor1 = $first->json('meta.next_cursor');
        $this->assertIsString($cursor1);

        $second = $this->getJson(route('api.v1.venues.index', ['limit' => 2, 'cursor' => $cursor1]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', true);

        $third = $this->getJson(route('api.v1.venues.index', [
            'limit' => 2,
            'cursor' => $second->json('meta.next_cursor'),
        ]))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.next_cursor', null)
            ->assertJsonPath('links.next', null);

        $collected = array_merge(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
            array_column($third->json('data'), 'id'),
        );

        $this->assertSame($venues->pluck('id')->all(), $collected);
    }

    public function test_show_public_venue_returns_a_single_object_with_the_same_fields_as_the_list(): void
    {
        $venue = Venue::factory()->create([
            'name' => 'Школа №1794',
            'status' => VenueStatusEnum::CONFIRMED->value,
            'raw_address' => 'Москва, Керамический проезд, 47А',
        ]);

        $list = $this->getJson(route('api.v1.venues.index'))
            ->assertOk()->json('data.0');

        $this->withHeader('Origin', 'https://external.example')
            ->getJson(route('api.v1.venues.show', ['venue' => $venue->id]))
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials')
            ->assertExactJson(['data' => $list])
            ->assertJsonPath('data.name', 'Школа №1794')
            ->assertJsonPath('data.id', $venue->id)
            ->assertJsonStructure(['data' => [
                'id', 'name', 'alias', 'type', 'type_label', 'status', 'operational_status',
                'short_description', 'address', 'latitude', 'longitude', 'image_url', 'url',
            ]]);
    }

    public function test_show_returns_404_for_unconfirmed_blocked_deleted_and_missing_venues(): void
    {
        $unconfirmed = Venue::factory()->create(['status' => VenueStatusEnum::UNCONFIRMED->value]);
        $blocked = Venue::factory()->create(['status' => VenueStatusEnum::BLOCKED->value]);
        $deleted = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $deleted->delete();

        foreach ([$unconfirmed->id, $blocked->id, $deleted->id, 99999999, 0] as $venueId) {
            $this->getJson(route('api.v1.venues.show', ['venue' => $venueId]))
                ->assertNotFound()
                ->assertJsonPath('message', 'Площадка не найдена.');
        }

        $this->getJson('/api/v1/venues/invalid-id')->assertNotFound();
    }

    public function test_deprecated_get_venues_route_is_no_longer_available(): void
    {
        $this->getJson('/api/get_venues')->assertNotFound();
    }

    public function test_invalid_pagination_parameters_return_422(): void
    {
        $this->getJson(route('api.v1.venues.index', ['limit' => 101]))->assertUnprocessable();
        $this->getJson(route('api.v1.venues.index', ['limit' => 0]))->assertUnprocessable();
        $this->getJson(route('api.v1.venues.index', ['cursor' => 'not-a-valid-cursor']))->assertUnprocessable();
    }

    public function test_public_resource_includes_address_coordinates_and_absolute_photo_url(): void
    {
        $address = Address::factory()->create([
            'city' => 'Москва',
            'street' => 'Тестовая улица',
            'building' => '1',
            'full_address' => 'Москва, Тестовая улица, 1',
            'latitude' => 55.75,
            'longitude' => 37.62,
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);
        $venue = Venue::factory()->create([
            'name' => 'Basketball Hall',
            'status' => VenueStatusEnum::CONFIRMED->value,
            'location_id' => $location->id,
        ]);
        Media::factory()->create([
            'mediable_type' => $venue->getMorphClass(),
            'mediable_id' => $venue->id,
            'collection' => 'gallery',
            'disk' => 'public',
            'path' => 'venues/example.jpg',
            'is_featured' => true,
        ]);

        $this->getJson(route('api.v1.venues.index'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Basketball Hall')
            ->assertJsonPath('data.0.type', $venue->type->value)
            ->assertJsonPath('data.0.latitude', 55.75)
            ->assertJsonPath('data.0.longitude', 37.62)
            ->assertJsonPath('data.0.image_url', url('/storage/venues/example.jpg'))
            ->assertJsonPath('data.0.url', route('venues.show', $venue->routeIdentifier()));
    }

    public function test_public_api_allows_cross_origin_browser_requests_without_cookies(): void
    {
        Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);

        $this->withHeader('Origin', 'https://external.example')->getJson(route('api.v1.venues.index'))
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }
}
