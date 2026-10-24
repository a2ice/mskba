<?php

namespace Tests\Feature\Favorites;

use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Favorites\Application\Services\FavoritesManager;
use App\Modules\Favorites\Domain\Models\Favorite;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FavoritesFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function venue(): Venue
    {
        return Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
    }

    private function toggle(string $type, int $id): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('favorites.toggle'), ['type' => $type, 'id' => $id]);
    }

    public function test_anonymous_guest_can_add_trash_and_restore_a_venue_with_one_cookie(): void
    {
        $venue = $this->venue();
        $first = $this->toggle('venue', $venue->id)
            ->assertOk()->assertJsonPath('state', 'active')
            ->assertCookie(FavoritesManager::COOKIE_NAME);

        $cookie = collect($first->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === FavoritesManager::COOKIE_NAME);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower($cookie->getSameSite()));

        $this->withCookie(FavoritesManager::COOKIE_NAME, $cookie->getValue());
        $this->getJson(route('favorites.status', ['type' => 'venue', 'id' => $venue->id]))
            ->assertOk()->assertJsonPath('state', 'active');

        $this->toggle('venue', $venue->id)->assertOk()->assertJsonPath('state', 'trashed');
        $this->toggle('venue', $venue->id)->assertOk()->assertJsonPath('state', 'active');
        $this->assertDatabaseCount('favorites', 1);
    }

    public function test_guest_favorite_is_transferred_automatically_on_first_authenticated_request(): void
    {
        $venue = $this->venue();
        $response = $this->toggle('venue', $venue->id)->assertOk();
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === FavoritesManager::COOKIE_NAME);
        $user = User::factory()->create();
        $this->withCookie(FavoritesManager::COOKIE_NAME, $cookie->getValue())
            ->actingAs($user)->get(route('favorites.mine'))->assertOk()
            ->assertSee('Избранное');

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id, 'guest_token_hash' => null,
            'entity_type' => 'venue', 'entity_id' => $venue->id,
            'trashed_at' => null,
        ]);
        $this->getJson(route('favorites.items'))
            ->assertOk()->assertJsonPath('data.0.type', 'venue')
            ->assertJsonPath('data.0.title', $venue->name);
    }

    public function test_repeated_action_uses_trash_and_restores_to_same_record(): void
    {
        $user = User::factory()->create();
        $venue = $this->venue();

        $this->actingAs($user);
        $this->toggle('venue', $venue->id)->assertOk()->assertJsonPath('state', 'active');
        $this->toggle('venue', $venue->id)->assertOk()->assertJsonPath('state', 'trashed');
        $this->getJson(route('favorites.items', ['state' => 'active']))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('favorites.items', ['state' => 'trashed', 'type' => 'venue']))
            ->assertOk()->assertJsonPath('data.0.state', 'trashed');
        $this->toggle('venue', $venue->id)->assertOk()->assertJsonPath('state', 'active');
        $this->assertDatabaseCount('favorites', 1);
        $this->assertNull(Favorite::query()->sole()->trashed_at);
    }

    public function test_cross_account_favorites_are_private_and_guest_cannot_open_account(): void
    {
        $venue = $this->venue();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first);
        $this->toggle('venue', $venue->id)->assertOk();
        $this->actingAs($second)->getJson(route('favorites.items'))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('favorites', ['user_id' => $first->id]);
    }

    public function test_favorite_registry_rejects_private_events_and_blocked_venues(): void
    {
        $hiddenVenue = Venue::factory()->create(['status' => VenueStatusEnum::BLOCKED->value]);
        $hiddenEvent = Event::factory()->create(['visibility' => EventVisibilityEnum::PRIVATE->value]);
        $this->toggle('venue', $hiddenVenue->id)->assertNotFound();
        $this->toggle('event', $hiddenEvent->id)->assertNotFound();
        $this->toggle('bogus', 1)->assertUnprocessable();
        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_an_account_can_save_multiple_public_entity_types_and_filter_them(): void
    {
        $venue = $this->venue();
        $event = Event::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->toggle('venue', $venue->id)->assertOk();
        $this->toggle('event', $event->id)->assertOk();
        $this->getJson(route('favorites.items', ['type' => 'event']))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'event');
        $this->getJson(route('favorites.items', ['type' => 'all']))
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_guest_active_item_restores_an_account_item_in_the_trash(): void
    {
        $venue = $this->venue();
        $user = User::factory()->create();
        Favorite::query()->create([
            'user_id' => $user->id, 'entity_type' => 'venue',
            'entity_id' => $venue->id, 'trashed_at' => now(),
        ]);
        $guest = $this->toggle('venue', $venue->id)->assertOk();
        $cookie = collect($guest->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === FavoritesManager::COOKIE_NAME);

        $this->withCookie(FavoritesManager::COOKIE_NAME, $cookie->getValue())
            ->actingAs($user)->getJson(route('favorites.items'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.state', 'active');
        $this->assertDatabaseCount('favorites', 1);
    }
}
