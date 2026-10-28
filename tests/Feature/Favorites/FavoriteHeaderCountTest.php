<?php

namespace Tests\Feature\Favorites;

use App\Modules\Favorites\Application\Services\FavoriteCountService;
use App\Modules\Favorites\Domain\Models\Favorite;
use App\Modules\Favorites\Infrastructure\Broadcasting\FavoriteCountUpdatedBroadcast;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class FavoriteHeaderCountTest extends TestCase
{
    use RefreshDatabase;

    private function venue(): Venue
    {
        return Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
    }

    public function test_count_is_private_and_excludes_trashed_and_other_users(): void
    {
        $user = User::factory()->create();
        $another = User::factory()->create();
        $venue = $this->venue();

        $this->getJson(route('favorites.count'))->assertUnauthorized();

        Favorite::query()->create([
            'user_id' => $user->id, 'entity_type' => 'venue',
            'entity_id' => $venue->id, 'trashed_at' => null,
        ]);
        Favorite::query()->create([
            'user_id' => $another->id, 'entity_type' => 'event',
            'entity_id' => 999, 'trashed_at' => null,
        ]);
        Favorite::query()->create([
            'user_id' => $user->id, 'entity_type' => 'team',
            'entity_id' => 998, 'trashed_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson(route('favorites.count'))
            ->assertOk()->assertJsonPath('count', 1);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->actingAs($another)->getJson(route('favorites.count'))
            ->assertOk()->assertJsonPath('count', 1);
    }

    public function test_toggle_and_restore_invalidate_cached_count_and_broadcast_private_totals(): void
    {
        Event::fake([FavoriteCountUpdatedBroadcast::class]);
        $user = User::factory()->create();
        $venue = $this->venue();
        $this->actingAs($user);
        $counts = app(FavoriteCountService::class);

        $this->assertSame(0, $counts->forUser($user));
        $this->postJson(route('favorites.toggle'), ['type' => 'venue', 'id' => $venue->id])
            ->assertOk()->assertJsonPath('state', 'active')
            ->assertJsonPath('favorites_count', 1);
        $this->assertSame(1, $counts->forUser($user));
        $this->postJson(route('favorites.toggle'), ['type' => 'venue', 'id' => $venue->id])
            ->assertOk()->assertJsonPath('state', 'trashed')
            ->assertJsonPath('favorites_count', 0);
        $this->assertSame(0, $counts->forUser($user));
        $this->postJson(route('favorites.toggle'), ['type' => 'venue', 'id' => $venue->id])
            ->assertOk()->assertJsonPath('state', 'active')
            ->assertJsonPath('favorites_count', 1);

        Event::assertDispatched(FavoriteCountUpdatedBroadcast::class, 3);
        Event::assertDispatched(FavoriteCountUpdatedBroadcast::class,
            static fn (FavoriteCountUpdatedBroadcast $event): bool =>
                $event->userId === $user->id && $event->count === 0);
    }

    public function test_header_contains_accessible_favorites_link_for_guest_and_account(): void
    {
        $this->get(route('welcome'))->assertOk()
            ->assertSee('data-header-favorites', false)
            ->assertSee('data-auth-redirect="/account/favorites"', false);

        $this->actingAs(User::factory()->create())
            ->get(route('welcome'))->assertOk()
            ->assertSee('data-header-favorites', false)
            ->assertSee('data-favorites-sync-url=', false);
    }
}
