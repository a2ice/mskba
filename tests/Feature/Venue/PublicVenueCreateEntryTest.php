<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Application\UseCases\CreateAccountVenueHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PublicVenueCreateEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_primary_venues_action_links_to_public_catalog(): void
    {
        $this
            ->get(route('welcome'))
            ->assertOk()
            ->assertSee('Площадки')
            ->assertSee('href="'.route('venues').'"', false)
            ->assertDontSee('data-modal-target="create-game"', false);
    }

    public function test_guest_sees_auth_gate_for_create_action_on_public_venues_page(): void
    {
        $this
            ->get(route('venues'))
            ->assertOk()
            ->assertSee('data-mobile-primary-bar', false)
            ->assertSee('data-venue-catalog', false)
            ->assertSee('data-venue-filter-toggle', false)
            ->assertSee('data-venue-view="list"', false)
            ->assertSee('data-venue-view="map"', false)
            ->assertSee('На сегодня игр нет')
            ->assertSee('Добавить')
            ->assertSee('data-modal-target="auth-entry-classic"', false)
            ->assertSee('data-auth-redirect-url="'.route('venues.create', [], false).'"', false)
            ->assertSee(route('venues.create', [], false));
    }

    public function test_public_venues_catalog_filters_visible_venues_and_exposes_map_points(): void
    {
        $address = Address::factory()->create([
            'latitude' => 55.751244,
            'longitude' => 37.618423,
            'full_address' => 'Москва, Тестовая улица, 1',
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);
        Venue::factory()->create([
            'name' => 'Бесплатная улица',
            'location_id' => $location->id,
            'raw_address' => 'Москва, Тестовая улица, 1',
            'type' => VenueTypeEnum::STREET_COURT,
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        Venue::factory()->create([
            'name' => 'Платный зал',
            'type' => VenueTypeEnum::SPORTS_HALL,
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => true,
        ]);

        $this->get(route('venues', [
            'type' => VenueTypeEnum::STREET_COURT->value,
            'access' => 'free',
            'view' => 'map',
        ]))
            ->assertOk()
            ->assertSee('Бесплатная улица')
            ->assertDontSee('Платный зал')
            ->assertSee('data-venue-catalog-map', false)
            ->assertSee('55.751244', false)
            ->assertSee('37.618423', false);
    }

    public function test_unconfirmed_user_sees_create_link_on_public_venues_page(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($user)
            ->get(route('venues'))
            ->assertOk()
            ->assertSee('Добавить')
            ->assertSee(route('venues.create', [], false));
    }

    public function test_confirmed_user_sees_create_link_on_public_venues_page(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this
            ->actingAs($user)
            ->get(route('venues'))
            ->assertOk()
            ->assertSee('Добавить')
            ->assertSee(route('venues.create', [], false));
    }

    public function test_create_page_requires_account_and_shows_form_for_authenticated_users(): void
    {
        $confirmedUser = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $unconfirmedUser = User::factory()->create([
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->get(route('venues.create'))
            ->assertRedirect(route('login'));

        $this
            ->actingAs($unconfirmedUser)
            ->get(route('venues.create'))
            ->assertOk()
            ->assertSee('Добавить площадку')
            ->assertSee('data-address-clear', false)
            ->assertSee('Я на площадке')
            ->assertDontSee('Краткое описание')
            ->assertDontSee('Полное описание')
            ->assertDontSee('Теги')
            ->assertSee('Ближайшее метро')
            ->assertSee('Подставится после выбора адреса')
            ->assertSeeInOrder(['data-address-suggest-list', 'data-address-current-location'], false)
            ->assertSee('data-address-proximity-warning', false)
            ->assertSee(route('venues.proximity-check'), false)
            ->assertSee(route('integrations.address-reverse'), false)
            ->assertSee(route('venues.store', [], false));

        $this
            ->actingAs($confirmedUser)
            ->get(route('venues.create'))
            ->assertOk()
            ->assertSee('Добавить площадку')
            ->assertSee(route('venues.store', [], false));
    }

    public function test_proximity_check_warns_only_for_confirmed_venue_of_same_type_within_strong_radius(): void
    {
        $address = Address::factory()->create([
            'latitude' => 55.751244,
            'longitude' => 37.618423,
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);

        Venue::factory()->create([
            'location_id' => $location->id,
            'type' => VenueTypeEnum::STREET_COURT,
            'status' => VenueStatusEnum::CONFIRMED,
        ]);

        $this->getJson(route('venues.proximity-check', [
            'type' => VenueTypeEnum::STREET_COURT->value,
            'latitude' => 55.7513,
            'longitude' => 37.6184,
        ]))
            ->assertOk()
            ->assertJsonPath('has_conflict', true)
            ->assertJsonPath('radius_meters', 50)
            ->assertJsonPath('message', 'Рядом уже есть такая площадка.');

        $this->getJson(route('venues.proximity-check', [
            'type' => VenueTypeEnum::SPORTS_HALL->value,
            'latitude' => 55.7513,
            'longitude' => 37.6184,
        ]))
            ->assertOk()
            ->assertJsonPath('has_conflict', false)
            ->assertJsonPath('message', null);

        $this->getJson(route('venues.proximity-check', [
            'type' => VenueTypeEnum::STREET_COURT->value,
            'latitude' => 55.7530,
            'longitude' => 37.6184,
        ]))
            ->assertOk()
            ->assertJsonPath('has_conflict', false);
    }

    public function test_confirmed_user_can_submit_venue_from_public_create_page(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('venues.store'), [
                'name' => 'Публичная площадка',
                'type' => VenueTypeEnum::STREET_COURT->value,
                'short_description' => 'Описание публичной площадки',
                'location' => $this->locationPayload('Москва, Тестовая улица, 1'),
            ]);

        $this->assertDatabaseHas('venues', [
            'name' => 'Публичная площадка',
            'raw_address' => 'Москва, Тестовая улица, 1',
        ]);

        $venue = Venue::query()->where('name', 'Публичная площадка')->firstOrFail();
        $response->assertRedirect(route('account.venues.edit', $venue->routeIdentifier()));
        $actor = $venue->creatorActor()->firstOrFail();

        $this->assertSame($user->id, $actor->user_id);
        $this->assertNotNull($actor->user_fingerprint_id);
    }

    public function test_guest_cannot_submit_venue(): void
    {
        $this
            ->post(route('venues.store'), [
                'name' => 'Гостевая площадка',
                'type' => VenueTypeEnum::STREET_COURT->value,
                'short_description' => 'Описание гостевой площадки',
                'location' => $this->locationPayload('Москва, Тестовая улица, 2'),
            ])
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('venues', ['name' => 'Гостевая площадка']);
    }

    public function test_unconfirmed_user_can_submit_venue_from_public_create_page(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($user)
            ->from(route('venues.create'))
            ->post(route('venues.store'), [
                'name' => 'Черновик площадки',
                'type' => VenueTypeEnum::STREET_COURT->value,
                'short_description' => 'Описание',
                'location' => $this->locationPayload('Москва, Тестовая улица, 2'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('venues', [
            'name' => 'Черновик площадки',
        ]);

        $venue = Venue::query()->where('name', 'Черновик площадки')->firstOrFail();
        $actor = $venue->creatorActor()->firstOrFail();

        $this->assertSame($user->id, $actor->user_id);
        $this->assertNotNull($actor->user_fingerprint_id);
    }

    public function test_show_page_prefers_current_user_venue_when_alias_has_duplicates(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $userActor = app(CurrentActorResolver::class)->resolve($user, null);
        $otherUser = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $otherUserActor = app(CurrentActorResolver::class)->resolve($otherUser, null);

        Venue::factory()->create([
            'created_by_actor_id' => $otherUserActor->id,
            'name' => 'Чужая версия площадки',
            'alias' => 'shared-alias',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);
        Venue::factory()->create([
            'created_by_actor_id' => $userActor->id,
            'name' => 'Моя версия площадки',
            'alias' => 'shared-alias',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($user)
            ->get(route('venues.show', 'shared-alias'))
            ->assertOk()
            ->assertSee('Моя версия площадки')
            ->assertDontSee('Чужая версия площадки');
    }

    public function test_same_user_cannot_create_second_unconfirmed_duplicate_venue(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $actor = app(CurrentActorResolver::class)->resolve($user, null);
        $createVenue = app(CreateAccountVenueHandler::class);

        $createVenue->handle($actor, [
            'name' => 'Повторная площадка',
            'type' => VenueTypeEnum::STREET_COURT->value,
            'short_description' => 'Первая заявка',
        ], $this->locationDto('Москва, Тестовая улица, 91', 55.7000000, 37.6000000));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Вы уже добавили площадку такого типа рядом с этой точкой.');

        $createVenue->handle($actor, [
            'name' => 'Повторная площадка',
            'type' => VenueTypeEnum::STREET_COURT->value,
            'short_description' => 'Вторая заявка',
        ], $this->locationDto('Москва, Тестовая улица, 92', 55.7001000, 37.6001000));
    }

    public function test_different_users_can_create_unconfirmed_duplicate_venues(): void
    {
        $firstUser = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $secondUser = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $createVenue = app(CreateAccountVenueHandler::class);

        $firstVenue = $createVenue->handle(app(CurrentActorResolver::class)->resolve($firstUser, null), [
            'name' => 'Общая площадка',
            'type' => VenueTypeEnum::STREET_COURT->value,
            'short_description' => 'Первая заявка',
        ], $this->locationDto('Москва, Тестовая улица, 93', 55.7000000, 37.6000000));
        $secondVenue = $createVenue->handle(app(CurrentActorResolver::class)->resolve($secondUser, null), [
            'name' => 'Общая площадка',
            'type' => VenueTypeEnum::STREET_COURT->value,
            'short_description' => 'Вторая заявка',
        ], $this->locationDto('Москва, Тестовая улица, 93', 55.7000000, 37.6000000));

        $this->assertSame($firstVenue->alias, $secondVenue->alias);
        $this->assertNotSame($firstVenue->id, $secondVenue->id);
    }

    /** @return array<string, mixed> */
    private function locationPayload(string $rawAddress): array
    {
        $offset = (abs(crc32($rawAddress)) % 10_000) / 1_000_000;

        return [
            'raw_address' => $rawAddress,
            'address_selected' => '1',
            'city' => 'Москва',
            'street' => 'Тестовая улица',
            'building' => '1',
            'latitude' => 55.7 + $offset,
            'longitude' => 37.6 + $offset,
        ];
    }

    private function locationDto(string $rawAddress, float $latitude, float $longitude): CreateLocationDTO
    {
        return new CreateLocationDTO(
            rawAddress: $rawAddress,
            city: 'Москва',
            street: 'Тестовая улица',
            building: '1',
            latitude: $latitude,
            longitude: $longitude,
        );
    }
}
