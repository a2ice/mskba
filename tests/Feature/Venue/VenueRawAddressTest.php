<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Domain\Models\MetroLine;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Venue\Application\UseCases\CreateAccountVenueHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Infrastructure\Jobs\FindVenueDuplicatesJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
                'requires_payment' => '1',
                'requires_booking_approval' => '1',
                'short_description' => 'Описание тестовой площадки',
                'tags' => 'крытая, паркет',
                'location' => $this->locationPayload('Москва, ул. Летниковская, 12', 55.7280000, 37.6440000),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('venues', [
            'name' => 'Тестовая площадка',
            'alias' => 'testovaya-ploshchadka',
            'raw_address' => 'Москва, ул. Летниковская, 12',
            'requires_payment' => null,
            'requires_booking_approval' => true,
        ]);
        $this->assertDatabaseHas('venue_tags', [
            'name' => 'крытая',
            'slug' => 'krytaya',
        ]);
    }

    public function test_can_create_venue_with_duplicate_alias_at_another_location(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);

        $this
            ->actingAs($user)
            ->post(route('venues.store'), [
                'name' => 'test',
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'short_description' => 'Первая площадка',
                'location' => $this->locationPayload('Москва, ул. Летниковская, 12', 55.7280000, 37.6440000),
            ])
            ->assertRedirect();

        Venue::query()
            ->where('name', 'test')
            ->update(['status' => VenueStatusEnum::CONFIRMED->value]);

        $this
            ->actingAs($user)
            ->from(route('venues.create'))
            ->post(route('venues.store'), [
                'name' => 'TeSt',
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'short_description' => 'Дубль названия',
                'location' => $this->locationPayload('Москва, ул. Летниковская, 14', 55.7380000, 37.6540000),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('venues', [
            'name' => 'test',
            'alias' => 'test',
        ]);
        $this->assertDatabaseHas('venues', [
            'name' => 'TeSt',
        ]);
        $this->assertDatabaseMissing('venues', [
            'alias' => 'test-2',
        ]);
    }

    public function test_can_create_venue_of_different_type_with_same_confirmed_name_and_address(): void
    {
        $createVenue = app(CreateAccountVenueHandler::class);
        $actor = $this->userActor();

        $indoorVenue = $createVenue->handle($actor, [
            'name' => 'Школьная площадка',
            'type' => VenueTypeEnum::SPORTS_HALL->value,
            'short_description' => 'Спортивный зал',
        ], $this->locationDto('Москва, Школьная улица, 1', 55.7000000, 37.6000000));
        $indoorVenue->forceFill(['status' => VenueStatusEnum::CONFIRMED])->save();

        $outdoorVenue = $createVenue->handle($actor, [
            'name' => 'Школьная площадка',
            'type' => VenueTypeEnum::STREET_COURT->value,
            'short_description' => 'Уличная площадка',
        ], $this->locationDto('Москва, Школьная улица, 1', 55.7000000, 37.6000000));

        $this->assertSame($indoorVenue->alias, $outdoorVenue->alias);
        $this->assertSame(VenueTypeEnum::STREET_COURT, $outdoorVenue->type);
        $this->assertDatabaseCount('venues', 2);
    }

    public function test_create_handler_allows_duplicate_name_at_another_location_without_alias_suffix(): void
    {
        $createVenue = app(CreateAccountVenueHandler::class);
        $actor = $this->userActor();

        $venue = $createVenue->handle($actor, [
            'name' => 'test',
            'type' => VenueTypeEnum::SPORTS_HALL->value,
            'short_description' => 'Первая площадка',
        ], $this->locationDto('Москва, ул. Летниковская, 12', 55.7280000, 37.6440000));
        $venue->forceFill(['status' => VenueStatusEnum::CONFIRMED])->save();

        $secondVenue = $createVenue->handle($actor, [
            'name' => 'TEST',
            'type' => VenueTypeEnum::SPORTS_HALL->value,
            'short_description' => 'Одноименная площадка',
        ], $this->locationDto('Москва, ул. Летниковская, 14', 55.7380000, 37.6540000));

        $this->assertSame('test', $secondVenue->alias);
    }

    public function test_can_create_unconfirmed_duplicate_name_without_alias_suffix_and_queues_detection(): void
    {
        Queue::fake();

        $createVenue = app(CreateAccountVenueHandler::class);
        $actor = $this->userActor();

        $firstVenue = $createVenue->handle($actor, [
            'name' => 'test',
            'type' => VenueTypeEnum::SPORTS_HALL->value,
            'short_description' => 'Первая площадка',
        ], $this->locationDto('Москва, ул. Летниковская, 12', 55.7280000, 37.6440000));

        $secondVenue = $createVenue->handle($actor, [
            'name' => 'TEST',
            'type' => VenueTypeEnum::SPORTS_HALL->value,
            'short_description' => 'Дубль названия',
        ], $this->locationDto('Москва, ул. Летниковская, 14', 55.7380000, 37.6540000));

        $this->assertSame('test', $firstVenue->alias);
        $this->assertSame('test', $secondVenue->alias);
        $this->assertDatabaseCount('venues', 2);

        Queue::assertPushed(FindVenueDuplicatesJob::class, 2);
    }

    public function test_cannot_create_venue_with_duplicate_confirmed_raw_address(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);

        $this
            ->actingAs($user)
            ->post(route('venues.store'), [
                'name' => 'Первая площадка по адресу',
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'short_description' => 'Описание',
                'location' => $this->locationPayload('Москва, ул. Летниковская, 12', 55.7280000, 37.6440000),
            ])
            ->assertRedirect();

        Venue::query()
            ->where('name', 'Первая площадка по адресу')
            ->update(['status' => VenueStatusEnum::CONFIRMED->value]);

        $this
            ->actingAs($user)
            ->from(route('venues.create'))
            ->post(route('venues.store'), [
                'name' => 'Вторая площадка по адресу',
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'short_description' => 'Описание',
                'location' => $this->locationPayload('Москва, ул. Летниковская, 12', 55.7281000, 37.6441000),
            ])
            ->assertRedirect(route('venues.create'))
            ->assertSessionHasErrors('location.raw_address');

        $this->assertDatabaseMissing('venues', [
            'name' => 'Вторая площадка по адресу',
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
                'short_description' => 'Описание площадки с метро',
                'location' => [
                    'raw_address' => 'Москва, ул. Летниковская, 12',
                    'address_selected' => '1',
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

    public function test_cannot_create_same_type_near_confirmed_venue(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);

        $this
            ->actingAs($user)
            ->post(route('venues.store'), [
                'name' => 'Площадка с адресными полями',
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'short_description' => 'Описание',
                'location' => $this->locationPayload('Москва, Летниковская, 12', 55.7280000, 37.6440000),
            ])
            ->assertRedirect();

        Venue::query()
            ->where('name', 'Площадка с адресными полями')
            ->update(['status' => VenueStatusEnum::CONFIRMED->value]);

        $this
            ->actingAs($user)
            ->from(route('venues.create'))
            ->post(route('venues.store'), [
                'name' => 'Дубль адресных полей',
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'short_description' => 'Описание',
                'location' => $this->locationPayload('Москва, Другое название адреса, 99', 55.7281000, 37.6441000),
            ])
            ->assertRedirect(route('venues.create'))
            ->assertSessionHasErrors('location.raw_address');

        $this->assertDatabaseMissing('venues', [
            'name' => 'Дубль адресных полей',
        ]);
    }

    /** @return array<string, mixed> */
    private function locationPayload(string $rawAddress, float $latitude, float $longitude): array
    {
        return [
            'raw_address' => $rawAddress,
            'address_selected' => '1',
            'city' => 'Москва',
            'street' => 'Летниковская',
            'building' => '12',
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    private function locationDto(string $rawAddress, float $latitude, float $longitude): CreateLocationDTO
    {
        return new CreateLocationDTO(
            rawAddress: $rawAddress,
            city: 'Москва',
            street: 'Летниковская',
            building: '12',
            latitude: $latitude,
            longitude: $longitude,
        );
    }

    private function userActor(): Actor
    {
        $user = User::factory()->create();

        return Actor::factory()->create([
            'type' => ActorTypeEnum::USER,
            'user_id' => $user->id,
        ]);
    }
}
