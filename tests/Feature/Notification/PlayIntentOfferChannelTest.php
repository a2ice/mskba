<?php

namespace Tests\Feature\Notification;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlayIntentOfferChannelTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $extra = []): array
    {
        return array_replace([
            'name' => 'Условия для спецпредложений',
            'cadence' => 'flexible',
            'notify_matches' => false,
            'notify_venue_news' => false,
            'notify_offers' => true,
            'game_delivery_channels' => ['in_app'],
            'venue_delivery_channels' => ['in_app'],
            'offers_delivery_channels' => ['in_app'],
        ], $extra);
    }

    public function test_offer_channel_list_is_saved_and_returned_as_separate_subscription_setting(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $response = $this->postJson(route('play-intents.store'), $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.notify_offers', true)
            ->assertJsonPath('data.offers_delivery_channels.0', 'in_app');

        $id = $response->json('data.id');
        $this->assertSame(['in_app'], PlayIntent::findOrFail($id)->offers_delivery_channels);
        $this->getJson(route('play-intents.index'))->assertOk()
            ->assertJsonPath('data.0.offers_delivery_channels.0', 'in_app');

        // Disabling the notification type must keep the previous channel choice.
        $this->putJson(route('play-intents.update', $id), $this->validPayload([
            'notify_offers' => false,
        ]))->assertOk()->assertJsonPath('data.notify_offers', false);
        $this->assertSame(['in_app'], PlayIntent::findOrFail($id)->offers_delivery_channels);
    }

    public function test_enabled_offer_type_requires_a_available_channel(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson(route('play-intents.store'), $this->validPayload([
            'offers_delivery_channels' => ['telegram'],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['offers_delivery_channels']);

        $this->postJson(route('play-intents.store'), $this->validPayload([
            'offers_delivery_channels' => [],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['offers_delivery_channels']);

        $this->postJson(route('play-intents.store'), $this->validPayload([
            'offers_delivery_channels' => ['phone'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['offers_delivery_channels.0']);

        $this->assertDatabaseCount('play_intents', 0);
    }

    public function test_disabled_offer_type_does_not_revalidate_its_previously_selected_external_channel(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson(route('play-intents.store'), $this->validPayload([
            'notify_offers' => false,
            'offers_delivery_channels' => ['telegram'],
        ]))->assertCreated()->assertJsonPath('data.offers_delivery_channels.0', 'telegram');
    }

    public function test_game_and_venue_channels_remain_independent_from_offers(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson(route('play-intents.store'), $this->validPayload([
            'notify_matches' => true,
            'game_delivery_channels' => ['telegram'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['game_delivery_channels']);

        $this->postJson(route('play-intents.store'), $this->validPayload([
            'notify_matches' => false,
            'offers_delivery_channels' => ['in_app'],
            'game_delivery_channels' => ['telegram'],
        ]))->assertCreated()->assertJsonPath('data.game_delivery_channels.0', 'telegram');
    }
}
