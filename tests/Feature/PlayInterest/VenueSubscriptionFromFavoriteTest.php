<?php

namespace Tests\Feature\PlayInterest;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\VenueSubscription;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueSubscriptionFromFavoriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_favorite_and_notification_subscription_are_two_independent_operations(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $this->actingAs($user);

        $this->postJson(route('favorites.toggle'), ['type' => 'venue', 'id' => $venue->id])
            ->assertOk()->assertJsonPath('state', 'active');
        $this->assertDatabaseMissing('venue_subscriptions', ['user_id' => $user->id, 'venue_id' => $venue->id]);

        $this->postJson(route('favorites.preferences'), [
            'venue_id' => $venue->id, 'notify_upcoming_events' => true,
        ])->assertOk()->assertJsonPath('subscribed', true);
        $this->postJson(route('favorites.preferences'), [
            'venue_id' => $venue->id, 'notify_upcoming_events' => true,
        ])->assertOk()->assertJsonPath('subscribed', true);
        $this->assertDatabaseCount('venue_subscriptions', 1);
        $subscription = VenueSubscription::query()->firstOrFail();
        $this->assertSame(['in_app'], $subscription->channels());

        $this->getJson(route('play-intents.index').'?type=venue&status=active')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subscription_type', 'venue');

        $this->patchJson(route('venue-subscriptions.update', $subscription), ['active' => false])
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->getJson(route('play-intents.index').'?type=venue&status=active')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(route('play-intents.index').'?type=venue&status=cancelled')
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson(route('favorites.venue-statuses').'?ids%5B0%5D='.$venue->id)
            ->assertOk()->assertJsonPath('data.'.$venue->id.'.state', 'active')
            ->assertJsonPath('data.'.$venue->id.'.subscribed', false);

        $this->patchJson(route('venue-subscriptions.update', $subscription), ['active' => true])
            ->assertOk()->assertJsonPath('status', 'active');
        $this->postJson(route('favorites.toggle'), ['type' => 'venue', 'id' => $venue->id])
            ->assertOk()->assertJsonPath('state', 'trashed');
        $this->assertSame('active', $subscription->refresh()->status);
    }

    public function test_unlinked_external_channels_are_explicitly_unavailable(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson(route('subscriptions.channels'))
            ->assertOk()
            ->assertJsonPath('channels.in_app.available', true)
            ->assertJsonPath('channels.telegram.available', false)
            ->assertJsonPath('channels.email.available', false)
            ->assertJsonPath('channels.phone.available', false);
    }

    public function test_another_user_cannot_update_a_venue_subscription(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $subscription = VenueSubscription::query()->create([
            'user_id' => $owner->id, 'venue_id' => $venue->id,
            'status' => 'active', 'delivery_channels' => ['in_app'],
        ]);
        $this->actingAs($other)->patchJson(route('venue-subscriptions.update', $subscription), ['active' => false])
            ->assertNotFound();
        $this->assertSame('active', $subscription->refresh()->status);
    }
}
