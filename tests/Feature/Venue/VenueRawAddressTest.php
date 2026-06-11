<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\MetroLine;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueRawAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_user_can_create_venue_with_raw_address(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);

        $this
            ->actingAs($user)
            ->post(route('venues.store'), [
                'name' => 'Тестовая площадка',
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'description' => 'Описание тестовой площадки',
                'raw_address' => 'Москва, ул. Летниковская, 12',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('venues', [
            'name' => 'Тестовая площадка',
            'raw_address' => 'Москва, ул. Летниковская, 12',
        ]);
    }

    public function test_confirmed_user_can_create_venue_with_structured_location_and_metro(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);

        $line = MetroLine::factory()->create(['name' => 'Сокольническая линия']);
        $station = MetroStation::factory()->create([
            'metro_line_id' => $line->id,
            'name' => 'Парк культуры',
        ]);

        $this
            ->actingAs($user)
            ->post(route('venues.store'), [
                'name' => 'Площадка с метро',
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'description' => 'Описание площадки с метро',
                'location' => [
                    'raw_address' => 'Москва, ул. Летниковская, 12',
                    'city' => 'Москва',
                    'street' => 'Летниковская',
                    'building' => '12',
                    'postal_code' => '115114',
                    'latitude' => 55.728,
                    'longitude' => 37.644,
                    'metro_station_ids' => [$station->id],
                ],
            ])
            ->assertRedirect();

        $venue = Venue::query()
            ->where('name', 'Площадка с метро')
            ->firstOrFail();

        $this->assertNotNull($venue->location_id);
        $this->assertDatabaseHas('venues', [
            'id' => $venue->id,
            'raw_address' => 'Москва, ул. Летниковская, 12',
        ]);
        $this->assertDatabaseHas('addresses', [
            'full_address' => 'Москва, ул. Летниковская, 12',
            'city' => 'Москва',
            'street' => 'Летниковская',
            'building' => '12',
            'postal_code' => '115114',
        ]);
        $this->assertDatabaseHas('location_metro_station', [
            'location_id' => $venue->location_id,
            'metro_station_id' => $station->id,
        ]);
    }
}
