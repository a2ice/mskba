<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountVenuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_menu_shows_my_venues_for_unconfirmed_creator_without_participation_role(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($user)
            ->get(route('account'))
            ->assertOk()
            ->assertDontSee('Мои площадки');

        $actor = app(CurrentActorResolver::class)->resolve($user, null);

        Venue::factory()->create([
            'name' => 'Площадка пользователя без роли',
            'created_by_actor_id' => $actor->id,
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($user)
            ->get(route('account'))
            ->assertOk()
            ->assertSee('Мои площадки')
            ->assertSee(route('account.venues', absolute: false));
    }

    public function test_my_venues_page_uses_current_theme_components(): void
    {
        $user = User::factory()->create();
        $actor = app(CurrentActorResolver::class)->resolve($user, null);

        $venue = Venue::factory()->create([
            'name' => 'Площадка в кабинете',
            'created_by_actor_id' => $actor->id,
            'status' => VenueStatusEnum::UNCONFIRMED,
            'short_description' => 'Короткое описание площадки',
            'raw_address' => 'Москва, Тестовая улица, 7',
        ]);

        $this
            ->actingAs($user)
            ->get(route('account.venues'))
            ->assertOk()
            ->assertSee('Площадка в кабинете')
            ->assertSee('account-venue-list', false)
            ->assertSee('account-venue-status--unconfirmed', false)
            ->assertSee('Не подтверждён')
            ->assertSee('btn--primary', false)
            ->assertSee('btn--secondary', false)
            ->assertDontSee('btn-primary', false)
            ->assertDontSee('btn-success', false)
            ->assertDontSee('btn-outline-primary', false);

        $this
            ->actingAs($user)
            ->get(route('account.venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('account-venue-detail', false)
            ->assertSee('account-venue-status--unconfirmed', false)
            ->assertSee('Не подтверждён')
            ->assertSee('btn--secondary', false)
            ->assertSee('Модерация')
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertDontSee('btn-primary', false)
            ->assertDontSee('btn-outline-primary', false)
            ->assertDontSee('btn-outline-secondary', false);
    }

    public function test_venue_management_uses_authenticated_account_routes_only(): void
    {
        $user = User::factory()->create();
        $actor = app(CurrentActorResolver::class)->resolve($user, null);

        $venue = Venue::factory()->create([
            'created_by_actor_id' => $actor->id,
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $routeIdentifier = $venue->routeIdentifier();

        $this->get(route('account.venues.edit', $routeIdentifier))
            ->assertRedirect(route('login'));

        $this->actingAs($user)
            ->get(route('account.venues.show', $routeIdentifier))
            ->assertOk()
            ->assertSee(route('account.venues.edit', $routeIdentifier), false)
            ->assertSee(route('account.venues.status', $routeIdentifier), false);

        $this->actingAs($user)
            ->get(route('account.venues.edit', $routeIdentifier))
            ->assertOk()
            ->assertSee(route('account.venues.update', $routeIdentifier), false)
            ->assertSee(route('account.venues.moderation.submit', $routeIdentifier), false)
            ->assertSee(route('account.venues.photos.store', $routeIdentifier), false);

        $this->actingAs($user)
            ->get(route('account.venues.status', $routeIdentifier))
            ->assertOk()
            ->assertSee(route('account.venues.moderation.submit', $routeIdentifier), false);

        $this->get("/venues/{$routeIdentifier}/edit")->assertNotFound();
        $this->get("/venues/{$routeIdentifier}/status")->assertNotFound();
    }
}
