<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicVenueCreateEntryTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_confirmed_user_can_submit_venue_from_public_create_page(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this
            ->actingAs($user)
            ->post(route('venues.store'), [
                'name' => 'Публичная площадка',
                'type' => VenueTypeEnum::STREET_COURT->value,
                'description' => 'Описание публичной площадки',
                'raw_address' => 'Москва, Тестовая улица, 1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('venues', [
            'name' => 'Публичная площадка',
            'raw_address' => 'Москва, Тестовая улица, 1',
        ]);

        $venue = Venue::query()->where('name', 'Публичная площадка')->firstOrFail();
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
                'description' => 'Описание гостевой площадки',
                'raw_address' => 'Москва, Тестовая улица, 2',
            ])
            ->assertRedirect();

        $venue = Venue::query()->where('name', 'Гостевая площадка')->firstOrFail();

        $actor = $venue->creatorActor()->firstOrFail();

        $this->assertNull($actor->user_id);
        $this->assertNotNull($actor->user_fingerprint_id);

        $this
            ->withCookie('mskba_browser_fp', $response->getCookie('mskba_browser_fp')->getValue())
            ->get(route('venues.show', $venue->alias))
            ->assertOk()
            ->assertSee('Гостевая площадка');
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
                'description' => 'Описание гостевой площадки',
                'raw_address' => 'Москва, Тестовая улица, 12',
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
                'description' => 'Описание',
                'raw_address' => 'Москва, Тестовая улица, 14',
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
                'description' => 'Описание',
                'raw_address' => 'Москва, Тестовая улица, 2',
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
}
