<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserFingerprint;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Application\UseCases\CreateAccountVenueHandler;
use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
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

    public function test_guest_sees_create_link_on_public_venues_page(): void
    {
        $this
            ->get(route('venues'))
            ->assertOk()
            ->assertSee('Добавить площадку')
            ->assertSee(route('venues.create', [], false));
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
            ->assertSee('Добавить площадку')
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
            ->assertSee('Добавить площадку')
            ->assertSee(route('venues.create', [], false));
    }

    public function test_public_create_page_shows_venue_form_for_guests_and_users(): void
    {
        $confirmedUser = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $unconfirmedUser = User::factory()->create([
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);

        $this
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
            ->actingAs($unconfirmedUser)
            ->get(route('venues.create'))
            ->assertOk()
            ->assertSee('Добавить площадку')
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
        $response->assertRedirect(route('venues.edit', $venue->routeIdentifier()));
        $actor = $venue->creatorActor()->firstOrFail();

        $this->assertSame($user->id, $actor->user_id);
        $this->assertNotNull($actor->user_fingerprint_id);
    }

    public function test_guest_can_submit_venue_from_public_create_page(): void
    {
        $response = $this
            ->from(route('venues.create'))
            ->post(route('venues.store'), [
                'name' => 'Гостевая площадка',
                'type' => VenueTypeEnum::STREET_COURT->value,
                'short_description' => 'Описание гостевой площадки',
                'location' => $this->locationPayload('Москва, Тестовая улица, 2'),
            ])
            ->assertRedirect();

        $venue = Venue::query()->where('name', 'Гостевая площадка')->firstOrFail();
        $response->assertRedirect(route('venues.edit', $venue->routeIdentifier()));

        $actor = $venue->creatorActor()->firstOrFail();

        $this->assertNull($actor->user_id);
        $this->assertNotNull($actor->user_fingerprint_id);

        $this
            ->withCookie('mskba_browser_fp', $response->getCookie('mskba_browser_fp')->getValue())
            ->get(route('venues.edit', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('Гостевая площадка')
            ->assertSee('Краткое описание')
            ->assertSee('Полное описание')
            ->assertSee('Теги');
    }

    public function test_guest_created_unconfirmed_venue_is_listed_only_for_same_actor(): void
    {
        $response = $this
            ->from(route('venues.create'))
            ->post(route('venues.store'), [
                'name' => 'Площадка видна только создателю',
                'type' => VenueTypeEnum::STREET_COURT->value,
                'short_description' => 'Описание гостевой площадки',
                'location' => $this->locationPayload('Москва, Тестовая улица, 3'),
            ])
            ->assertRedirect();

        $venue = Venue::query()->where('name', 'Площадка видна только создателю')->firstOrFail();
        $fingerprintCookie = $response->getCookie('mskba_browser_fp')->getValue();

        $this
            ->withCookie('mskba_browser_fp', $fingerprintCookie)
            ->get(route('venues'))
            ->assertOk()
            ->assertSee('Площадка видна только создателю');

        $this
            ->withCookie('mskba_browser_fp', 'f3dc4ce6-2b9f-47bd-b9f1-89ec7d776f43')
            ->get(route('venues.show', $venue->alias))
            ->assertForbidden();

        $this
            ->withCookie('mskba_browser_fp', 'f3dc4ce6-2b9f-47bd-b9f1-89ec7d776f43')
            ->get(route('venues'))
            ->assertOk()
            ->assertDontSee('Площадка видна только создателю');
    }

    public function test_list_venues_handler_does_not_include_other_actor_unconfirmed_venue(): void
    {
        $creatorActor = Actor::factory()->create([
            'user_fingerprint_id' => UserFingerprint::query()->create([
                'fingerprint_hash' => hash('sha256', 'creator'),
                'visits_count' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ])->id,
        ]);
        $otherActor = Actor::factory()->create([
            'user_fingerprint_id' => UserFingerprint::query()->create([
                'fingerprint_hash' => hash('sha256', 'other'),
                'visits_count' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ])->id,
        ]);

        Venue::factory()->create([
            'name' => 'Площадка прямого use case',
            'created_by_actor_id' => $creatorActor->id,
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $creatorVisibleNames = collect(app(ListVenuesHandler::class)->handle(null, $creatorActor))
            ->pluck('name')
            ->all();
        $otherVisibleNames = collect(app(ListVenuesHandler::class)->handle(null, $otherActor))
            ->pluck('name')
            ->all();

        $this->assertContains('Площадка прямого use case', $creatorVisibleNames);
        $this->assertNotContains('Площадка прямого use case', $otherVisibleNames);
    }

    public function test_guest_venue_is_available_from_another_device_after_login_claims_guest_actor(): void
    {
        $user = User::factory()->create([
            'username' => 'guest_owner',
            'password' => 'password',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $otherUser = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $response = $this
            ->from(route('venues.create'))
            ->post(route('venues.store'), [
                'name' => 'Площадка из гостевого режима',
                'type' => VenueTypeEnum::STREET_COURT->value,
                'short_description' => 'Описание гостевой площадки',
                'location' => $this->locationPayload('Москва, Тестовая улица, 12'),
            ])
            ->assertRedirect();

        $fingerprintCookie = $response->getCookie('mskba_browser_fp')->getValue();
        $venue = Venue::query()->where('name', 'Площадка из гостевого режима')->firstOrFail();
        $guestActor = $venue->creatorActor()->firstOrFail();

        $this
            ->withCookie('mskba_browser_fp', $fingerprintCookie)
            ->post(route('auth.login'), [
                'login' => 'guest_owner',
                'password' => 'password',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('actor_claims', [
            'claimed_actor_id' => $guestActor->id,
            'claimed_by_user_id' => $user->id,
        ]);

        $this
            ->actingAs($user)
            ->withCookie('mskba_browser_fp', '8c4fd47f-a10d-4a48-8f6e-b1222a459e78')
            ->get(route('venues.show', $venue->alias))
            ->assertOk()
            ->assertSee('Площадка из гостевого режима');

        $this
            ->actingAs($otherUser)
            ->withCookie('mskba_browser_fp', 'c41663cb-b9e7-4641-996c-02c26d403bc7')
            ->get(route('venues.show', $venue->alias))
            ->assertForbidden();
    }

    public function test_guest_actor_is_not_automatically_claimed_by_second_user_from_same_browser(): void
    {
        $firstUser = User::factory()->create([
            'username' => 'first_guest_owner',
            'password' => 'password',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $secondUser = User::factory()->create([
            'username' => 'second_guest_owner',
            'password' => 'password',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $response = $this
            ->from(route('venues.create'))
            ->post(route('venues.store'), [
                'name' => 'Площадка первого гостя',
                'type' => VenueTypeEnum::STREET_COURT->value,
                'short_description' => 'Описание',
                'location' => $this->locationPayload('Москва, Тестовая улица, 14'),
            ])
            ->assertRedirect();

        $fingerprintCookie = $response->getCookie('mskba_browser_fp')->getValue();
        $venue = Venue::query()->where('name', 'Площадка первого гостя')->firstOrFail();
        $guestActor = $venue->creatorActor()->firstOrFail();

        $this
            ->withCookie('mskba_browser_fp', $fingerprintCookie)
            ->post(route('auth.login'), [
                'login' => 'first_guest_owner',
                'password' => 'password',
            ])
            ->assertRedirect();

        auth()->logout();

        $this
            ->withCookie('mskba_browser_fp', $fingerprintCookie)
            ->post(route('auth.login'), [
                'login' => 'second_guest_owner',
                'password' => 'password',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('actor_claims', [
            'claimed_actor_id' => $guestActor->id,
            'claimed_by_user_id' => $firstUser->id,
        ]);
        $this->assertDatabaseMissing('actor_claims', [
            'claimed_actor_id' => $guestActor->id,
            'claimed_by_user_id' => $secondUser->id,
        ]);

        $this
            ->actingAs($secondUser)
            ->withCookie('mskba_browser_fp', '9b113b31-8df3-49ff-9ddf-7f86583fbcb2')
            ->get(route('venues.show', $venue->alias))
            ->assertForbidden();
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
        $guestActor = Actor::factory()->create([
            'user_fingerprint_id' => UserFingerprint::query()->create([
                'fingerprint_hash' => hash('sha256', 'other-duplicate-owner'),
                'visits_count' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ])->id,
        ]);

        Venue::factory()->create([
            'created_by_actor_id' => $guestActor->id,
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
