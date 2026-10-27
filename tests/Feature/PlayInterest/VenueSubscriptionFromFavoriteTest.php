<?php

namespace Tests\Feature\PlayInterest;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\VenueSubscription;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
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

    public function test_favorite_subscription_has_a_real_name_and_full_editor_defaults(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $this->actingAs($user)->postJson(route('favorites.toggle'), [
            'type' => 'venue', 'id' => $venue->id,
        ])->assertOk();
        $this->postJson(route('favorites.preferences'), [
            'venue_id' => $venue->id, 'notify_upcoming_events' => true,
        ])->assertOk();

        $this->getJson(route('play-intents.index', ['type' => 'venue', 'status' => 'active']))
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Подписка на «'.$venue->name.'»')
            ->assertJsonPath('data.0.cadence', 'flexible')
            ->assertJsonPath('data.0.venue_id', $venue->id)
            ->assertJsonPath('data.0.notify_matches', false)
            ->assertJsonPath('data.0.notify_venue_news', true)
            ->assertJsonPath('data.0.show_on_venue', false)
            ->assertJsonPath('data.0.game_delivery_channels.0', 'in_app')
            ->assertJsonPath('data.0.venue_delivery_channels.0', 'in_app');
        $this->assertDatabaseCount('play_intents', 0);
    }

    public function test_full_editor_saves_name_criteria_and_game_notifications_without_duplicate_cards(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $subscription = VenueSubscription::query()->create([
            'user_id' => $user->id, 'venue_id' => $venue->id, 'status' => 'active',
            'delivery_channels' => ['in_app'],
        ]);
        $this->actingAs($user)->putJson(route('venue-subscriptions.details.update', $subscription), [
            'name' => 'Играю у школы после работы',
            'venue_id' => $venue->id,
            'cadence' => 'flexible',
            'weekdays' => [2, 4],
            'formats' => ['3x3'],
            'windows' => [['weekday' => 2, 'start_time' => '18:00', 'end_time' => '20:00']],
            'notify_matches' => true, 'notify_venue_news' => true,
            'game_delivery_channels' => ['in_app'],
            'venue_delivery_channels' => ['in_app'],
            'show_on_venue' => false,
        ])->assertOk()->assertJsonPath('data.name', 'Играю у школы после работы');

        $subscription->refresh();
        $this->assertNotNull($subscription->play_intent_id);
        $this->assertSame('Играю у школы после работы', $subscription->name);
        $this->assertSame([2, 4], $subscription->criteria['weekdays']);
        $this->assertDatabaseHas('play_intents', [
            'id' => $subscription->play_intent_id,
            'user_id' => $user->id, 'venue_id' => $venue->id,
            'notify_matches' => true,
        ]);
        $this->getJson(route('play-intents.index', ['type' => 'all', 'status' => 'active']))
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subscription_type', 'venue')
            ->assertJsonPath('data.0.name', 'Играю у школы после работы')
            ->assertJsonPath('data.0.weekdays.0', 2)
            ->assertJsonPath('data.0.formats.0', '3x3');

        $this->patchJson(route('venue-subscriptions.update', $subscription), ['active' => false])
            ->assertOk();
        $this->assertSame('cancelled', PlayIntent::findOrFail($subscription->play_intent_id)->status);
        $this->patchJson(route('venue-subscriptions.update', $subscription), ['active' => true])
            ->assertOk();
        $this->assertSame('active', PlayIntent::findOrFail($subscription->play_intent_id)->status);
    }

    public function test_full_editor_can_keep_venue_news_only_without_creating_game_search(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $subscription = VenueSubscription::query()->create([
            'user_id' => $user->id, 'venue_id' => $venue->id, 'status' => 'active',
            'delivery_channels' => ['in_app'],
        ]);
        $this->actingAs($user)->putJson(route('venue-subscriptions.details.update', $subscription), [
            'name' => 'Моя подписка',
            'venue_id' => $venue->id, 'cadence' => 'flexible',
            'notify_matches' => false, 'notify_venue_news' => true,
            'game_delivery_channels' => ['in_app'], 'venue_delivery_channels' => ['in_app'],
        ])->assertOk()->assertJsonPath('data.name', 'Моя подписка');
        $this->assertDatabaseCount('play_intents', 0);
        $this->assertSame('Моя подписка', $subscription->refresh()->name);

        $other = User::factory()->create();
        $this->actingAs($other)->putJson(route('venue-subscriptions.details.update', $subscription), [
            'venue_id' => $venue->id, 'cadence' => 'flexible',
        ])->assertNotFound();
    }

    public function test_removing_venue_turns_subscription_into_criteria_only_game_search(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $subscription = VenueSubscription::query()->create([
            'user_id' => $user->id, 'venue_id' => $venue->id, 'status' => 'active',
            'delivery_channels' => ['in_app'],
        ]);
        $this->actingAs($user)->putJson(route('venue-subscriptions.details.update', $subscription), [
            'name' => 'Любая подходящая площадка', 'venue_id' => null,
            'cadence' => 'flexible', 'notify_matches' => true,
            'notify_venue_news' => false, 'game_delivery_channels' => ['in_app'],
        ])->assertOk()->assertJsonPath('data.subscription_type', 'play');
        $this->assertSame('cancelled', $subscription->refresh()->status);
        $this->assertDatabaseHas('play_intents', [
            'user_id' => $user->id, 'venue_id' => null, 'notify_matches' => true,
        ]);
        $this->getJson(route('play-intents.index', ['type' => 'play', 'status' => 'active']))
            ->assertOk()->assertJsonPath('meta.total', 1);
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
