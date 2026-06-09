<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicVenueCreateEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_login_prompt_on_public_venues_page(): void
    {
        $this
            ->get(route('venues'))
            ->assertOk()
            ->assertSee('Чтобы добавить площадку, необходимо войти на сайт.')
            ->assertSee('data-modal-target="auth-entry-classic"', false);
    }

    public function test_unconfirmed_user_sees_confirmation_prompt_on_public_venues_page(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($user)
            ->get(route('venues'))
            ->assertOk()
            ->assertSee('Чтобы добавить площадку, необходимо подтвердить аккаунт.')
            ->assertSee(route('account.confirmation', [], false));
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

    public function test_public_create_page_shows_contextual_access_states(): void
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
            ->assertSee('Чтобы добавить площадку, необходимо войти на сайт.')
            ->assertSee('data-modal-target="auth-entry-classic"', false);

        $this
            ->actingAs($unconfirmedUser)
            ->get(route('venues.create'))
            ->assertOk()
            ->assertSee('Чтобы добавить площадку, необходимо подтвердить аккаунт.')
            ->assertSee(route('account.confirmation', [], false));

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
            'created_by_user_id' => $user->id,
            'raw_address' => 'Москва, Тестовая улица, 1',
        ]);
    }

    public function test_unconfirmed_user_is_redirected_back_from_public_venue_submit(): void
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
            ->assertRedirect(route('venues.create'))
            ->assertSessionHas('error', 'Чтобы добавить площадку, необходимо подтвердить аккаунт.');

        $this->assertDatabaseMissing('venues', [
            'name' => 'Черновик площадки',
        ]);
    }
}
