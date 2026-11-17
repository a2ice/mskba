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
        $this->withCredentials();
        $venue = $this->venue();
        $token = str_repeat('A', 64);
        $this->withCookie(FavoritesManager::COOKIE_NAME, $token);
        $this->toggle('venue', $venue->id)
            ->assertOk()->assertJsonPath('state', 'active');

        $this->withCookie(FavoritesManager::COOKIE_NAME, $token);
        $this->getJson(route('favorites.status', ['type' => 'venue', 'id' => $venue->id]))
            ->assertOk()->assertJsonPath('state', 'active');

        $this->toggle('venue', $venue->id)->assertOk()->assertJsonPath('state', 'trashed');
        $this->toggle('venue', $venue->id)->assertOk()->assertJsonPath('state', 'active');
        $this->assertDatabaseCount('favorites', 1);
    }

    public function test_first_anonymous_save_issues_a_securely_scoped_cookie(): void
    {
        $this->withCredentials();
        $venue = $this->venue();
        $result = $this->toggle('venue', $venue->id)
            ->assertOk()->assertJsonPath('state', 'active')
            ->assertCookie(FavoritesManager::COOKIE_NAME);
        $cookie = collect($result->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === FavoritesManager::COOKIE_NAME);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower($cookie->getSameSite()));

        // The encrypted response cookie is already encrypted, so send it
        // unchanged as a browser would. Laravel's JSON test requests require
        // withCredentials() to include the cookie at all.
        $this->withUnencryptedCookie(FavoritesManager::COOKIE_NAME, $cookie->getValue())
            ->getJson(route('favorites.status', ['type' => 'venue', 'id' => $venue->id]))
            ->assertOk()->assertJsonPath('state', 'active');
    }

    public function test_guest_favorite_is_transferred_automatically_on_first_authenticated_request(): void
    {
        $this->withCredentials();
        $venue = $this->venue();
        $token = str_repeat('B', 64);
        $this->withCookie(FavoritesManager::COOKIE_NAME, $token);
        $response = $this->toggle('venue', $venue->id)->assertOk();
        $user = User::factory()->create();
        $this->withCookie(FavoritesManager::COOKIE_NAME, $token)
            ->actingAs($user)->getJson(route('favorites.items'))->assertOk()
            ->assertJsonCount(1, 'data');

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

    public function test_notification_preference_requires_explicit_apply_and_is_private(): void
    {
        $venue = $this->venue();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);
        $this->toggle('venue', $venue->id)->assertOk()
            ->assertJsonPath('notify_upcoming_events', false);

        $this->postJson(route('favorites.preferences'), [
            'venue_id' => $venue->id,
            'notify_upcoming_events' => true,
        ])->assertOk()->assertJsonPath('notify_upcoming_events', true);
        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id, 'entity_type' => 'venue',
            'entity_id' => $venue->id, 'notify_upcoming_events' => true,
        ]);

        $this->actingAs($other)->postJson(route('favorites.preferences'), [
            'venue_id' => $venue->id,
            'notify_upcoming_events' => true,
        ])->assertNotFound();

        $this->actingAs($user);
        $this->toggle('venue', $venue->id)->assertOk()->assertJsonPath('state', 'trashed');
        $this->postJson(route('favorites.preferences'), [
            'venue_id' => $venue->id, 'notify_upcoming_events' => true,
        ])->assertNotFound();
    }

    public function test_upcoming_event_notification_is_sent_once_only_after_opt_in(): void
    {
        $venue = $this->venue();
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'venue_id' => $venue->id,
            'visibility' => \App\Modules\Event\Domain\Enums\EventVisibilityEnum::PUBLIC->value,
            'status' => \App\Modules\Event\Domain\Enums\EventStatusEnum::PUBLISHED->value,
            'starts_at' => now()->addDays(2),
        ]);

        $notifier = app(\App\Modules\Favorites\Application\Services\NotifyFavoriteVenueFollowers::class);
        $notifier->forEvent($event);
        $this->assertDatabaseCount('favorite_notification_deliveries', 0);

        $this->actingAs($user);
        $this->toggle('venue', $venue->id)->assertOk();
        $this->postJson(route('favorites.preferences'), [
            'venue_id' => $venue->id, 'notify_upcoming_events' => true,
        ])->assertOk();

        $notifier->forEvent($event);
        $notifier->forEvent($event);
        $this->assertDatabaseCount('favorite_notification_deliveries', 1);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'title' => 'Новая игра или тренировка на избранной площадке',
        ]);
    }

    public function test_guest_active_item_restores_an_account_item_in_the_trash(): void
    {
        $this->withCredentials();
        $venue = $this->venue();
        $user = User::factory()->create();
        Favorite::query()->create([
            'user_id' => $user->id, 'entity_type' => 'venue',
            'entity_id' => $venue->id, 'trashed_at' => now(),
        ]);
        $token = str_repeat('C', 64);
        $this->withCookie(FavoritesManager::COOKIE_NAME, $token);
        $guest = $this->toggle('venue', $venue->id)->assertOk();

        $this->withCookie(FavoritesManager::COOKIE_NAME, $token)
            ->actingAs($user)->getJson(route('favorites.items'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.state', 'active');
        $this->assertDatabaseCount('favorites', 1);
    }
}
