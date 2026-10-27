<?php

namespace Tests\Feature\PlayInterest;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VenueSubscriptionFromFavoriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_favorite_does_not_create_a_game_subscription_until_user_opts_in(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $this->actingAs($user);

        $this->postJson(route('favorites.toggle'), ['type' => 'venue', 'id' => $venue->id])
            ->assertOk()->assertJsonPath('state', 'active');
        $this->assertDatabaseCount('play_intents', 0);

        $this->postJson(route('favorites.preferences'), [
            'venue_id' => $venue->id, 'notify_upcoming_events' => true,
        ])->assertOk()->assertJsonPath('subscribed', true);

        $this->postJson(route('favorites.preferences'), [
            'venue_id' => $venue->id, 'notify_upcoming_events' => true,
        ])->assertOk()->assertJsonPath('subscribed', true);

        $this->assertDatabaseCount('play_intents', 1);
        $intent = PlayIntent::query()->firstOrFail();
        $this->assertSame((int) $venue->id, $intent->venue_id);
        $this->assertSame((int) $user->id, $intent->user_id);
        $this->assertTrue($intent->notify_matches);
        $this->assertTrue($intent->notify_venue_news);
        $this->assertSame('flexible', $intent->cadence);
        $this->assertSame(['in_app'], $intent->venue_delivery_channels);
        $this->assertSame(['in_app'], $intent->game_delivery_channels);
        $this->assertFalse($intent->show_on_venue);
        $this->assertStringContainsString($venue->name, $intent->name);

        $this->getJson(route('play-intents.index').'?status=active')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subscription_type', 'play')
            ->assertJsonPath('data.0.name', $intent->name);
    }

    public function test_adding_to_favorites_does_not_cancel_an_existing_subscription(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $intent = PlayIntent::query()->create([
            'user_id' => $user->id, 'venue_id' => $venue->id,
            'name' => 'Моя игра', 'cadence' => 'flexible',
            'status' => 'active', 'notify_matches' => true,
            'notify_venue_news' => false,
        ]);
        $this->actingAs($user);
        $this->postJson(route('favorites.toggle'), ['type' => 'venue', 'id' => $venue->id])
            ->assertOk();
        $this->getJson(route('favorites.venue-statuses').'?ids%5B0%5D='.$venue->id)
            ->assertOk()->assertJsonPath('data.'.$venue->id.'.subscribed', true)
            ->assertJsonPath('data.'.$venue->id.'.subscription_id', $intent->id);

        $this->postJson(route('favorites.preferences'), [
            'venue_id' => $venue->id, 'notify_upcoming_events' => true,
        ])->assertOk()->assertJsonPath('subscribed', true);

        $this->assertDatabaseCount('play_intents', 1);
        $this->assertSame('Моя игра', $intent->refresh()->name);
        $this->assertTrue($intent->notify_venue_news);

        $this->postJson(route('favorites.toggle'), ['type' => 'venue', 'id' => $venue->id])
            ->assertOk()->assertJsonPath('state', 'trashed');
        $this->assertSame('active', $intent->refresh()->status);
    }

    public function test_legacy_active_venue_news_merges_with_paused_search_without_reactivating_game_alerts(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $intent = PlayIntent::query()->create([
            'user_id' => $user->id, 'venue_id' => $venue->id,
            'name' => 'Вечерние игры', 'cadence' => 'flexible',
            'status' => 'paused', 'notify_matches' => true,
            'notify_venue_news' => false,
        ]);
        DB::table('venue_subscriptions')->insert([
            'user_id' => $user->id, 'venue_id' => $venue->id,
            'status' => 'active', 'delivery_channels' => json_encode(['in_app']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_10_27_153000_merge_venue_subscriptions_into_play_intents.php'
        );
        $migration->up();

        $this->assertDatabaseCount('play_intents', 1);
        $intent->refresh();
        $this->assertSame('active', $intent->status);
        $this->assertSame('Вечерние игры', $intent->name);
        $this->assertTrue($intent->notify_venue_news);
        $this->assertFalse($intent->notify_matches);
        $this->assertSame(['in_app'], $intent->venue_delivery_channels);
    }

    public function test_unlinked_external_delivery_channels_remain_unavailable(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson(route('subscriptions.channels'))
            ->assertOk()
            ->assertJsonPath('channels.in_app.available', true)
            ->assertJsonPath('channels.telegram.available', false)
            ->assertJsonPath('channels.email.available', false)
            ->assertJsonPath('channels.phone.available', false);
    }

    public function test_preference_cannot_be_changed_by_another_user(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner)->postJson(route('favorites.toggle'), [
            'type' => 'venue', 'id' => $venue->id,
        ])->assertOk();
        $this->actingAs($other)->postJson(route('favorites.preferences'), [
            'venue_id' => $venue->id, 'notify_upcoming_events' => true,
        ])->assertNotFound();
        $this->assertDatabaseCount('play_intents', 0);
    }
}
