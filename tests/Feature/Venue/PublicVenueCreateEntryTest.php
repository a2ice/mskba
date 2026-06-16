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
