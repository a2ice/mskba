<?php

namespace Tests\Feature\Venue;

use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
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

    public function test_filters_match_web_catalog_type_operational_status_and_access_rules(): void
    {
        $free = Venue::factory()->create([
            'name' => 'School Free Court',
            'type' => VenueTypeEnum::SCHOOL->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $paid = Venue::factory()->create([
            'name' => 'School Paid Court',
            'type' => VenueTypeEnum::SCHOOL->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => VenueOperationalStatusEnum::TEMPORARILY_CLOSED->value,
            'requires_payment' => true,
            'requires_booking_approval' => false,
        ]);
        $approval = Venue::factory()->create([
            'name' => 'Approval Hall',
            'type' => VenueTypeEnum::SPORTS_HALL->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => false,
            'requires_booking_approval' => true,
        ]);
        $unknown = Venue::factory()->create([
            'name' => 'Unknown Street Court',
            'type' => VenueTypeEnum::STREET_COURT->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => null,
            'requires_booking_approval' => false,
        ]);
        Venue::factory()->create([
            'name' => 'School Free Hidden',
            'type' => VenueTypeEnum::SCHOOL->value,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);

        $checks = [
            [['type' => 'school'], [$free->id, $paid->id]],
            [['operational_status' => 'temporarily_closed'], [$paid->id]],
            [['access' => 'free'], [$free->id]],
            [['access' => 'paid'], [$paid->id]],
            [['access' => 'approval'], [$approval->id]],
            [['type' => 'school', 'operational_status' => 'active', 'access' => 'free'], [$free->id]],
            [['search' => 'school free'], [$free->id]],
            [['type' => 'street_court', 'access' => 'free'], []],
        ];

        foreach ($checks as [$filters, $expected]) {
            $actual = $this->getJson(route('api.v1.venues.index', $filters))
                ->assertOk()
                ->json('data');
            $this->assertSame($expected, array_column($actual, 'id'), json_encode($filters));
        }

        // Nullable payment terms are not silently treated as free.
        $this->getJson(route('api.v1.venues.index', ['type' => 'street_court']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $unknown->id)
            ->assertJsonPath('data.0.requires_payment', null)
            ->assertJsonPath('data.0.has_free_access', false);

        $this->getJson(route('api.v1.venues.index', ['access' => 'free']))
            ->assertJsonPath('data.0.requires_payment', false)
            ->assertJsonPath('data.0.requires_booking_approval', false)
            ->assertJsonPath('data.0.has_free_access', true);
    }

    public function test_filters_are_preserved_across_cursor_pages_without_unrelated_venues(): void
    {
        $schools = collect(range(1, 3))->map(fn (int $n): Venue => Venue::factory()->create([
            'name' => "School Court {$n}",
            'type' => VenueTypeEnum::SCHOOL->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]));
        Venue::factory()->create([
            'name' => 'Paid School',
            'type' => VenueTypeEnum::SCHOOL->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => true,
        ]);
        Venue::factory()->create([
            'name' => 'Free Hall',
            'type' => VenueTypeEnum::SPORTS_HALL->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);

        $filters = ['type' => 'school', 'access' => 'free', 'limit' => 2];
        $first = $this->getJson(route('api.v1.venues.index', $filters))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', true);

        $next = $first->json('links.next');
        $this->assertIsString($next);
        parse_str((string) parse_url($next, PHP_URL_QUERY), $params);
        $this->assertSame('school', $params['type']);
        $this->assertSame('free', $params['access']);
        $this->assertSame('2', (string) $params['limit']);
        $this->assertSame($first->json('meta.next_cursor'), $params['cursor']);

        $second = $this->getJson($next)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('links.next', null);

        $ids = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'));
        $this->assertSame($schools->pluck('id')->all(), $ids);
    }

    public function test_invalid_venue_filter_parameters_return_422(): void
    {
        foreach ([
            ['type' => 'stadium_nonexistent'],
            ['operational_status' => 'blocked'],
            ['access' => 'unrestricted'],
            ['search' => str_repeat('x', 101)],
        ] as $filters) {
            $this->getJson(route('api.v1.venues.index', $filters))
                ->assertUnprocessable()
                ->assertJsonStructure(['message', 'errors']);
        }
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
