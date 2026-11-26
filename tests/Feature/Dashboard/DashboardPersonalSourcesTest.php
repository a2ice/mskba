<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Application\Providers\MyInterestsProvider;
use App\Modules\Dashboard\Application\Providers\WalletSummaryProvider;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\VenueSubscription;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardPersonalSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_interest_feed_is_personal_and_excludes_nonpublic_and_inactive_events(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'is_published' => true]);
        VenueSubscription::query()->create(['user_id' => $user->id, 'venue_id' => $venue->id, 'status' => 'active']);
        $public = Event::factory()->create(['venue_id' => $venue->id, 'type' => EventTypeEnum::TRAINING]);
        Event::factory()->create(['venue_id' => $venue->id, 'type' => EventTypeEnum::TRAINING, 'visibility' => EventVisibilityEnum::PRIVATE]);
        Event::factory()->create(['venue_id' => $venue->id, 'type' => EventTypeEnum::TRAINING, 'status' => EventStatusEnum::CANCELLED]);
        $provider = app(MyInterestsProvider::class);
        $data = $provider->getData($user, []);
        $this->assertSame(1, $data->total);
        $this->assertSame($public->id, $data->items[0]['id']);
        $this->assertSame(0, $provider->getData($other, [])->total);
        VenueSubscription::query()->where('user_id', $user->id)->update(['status' => 'cancelled']);
        $this->assertSame(0, $provider->getData($user, [])->total);
    }

    public function test_interest_horizon_is_configurable_and_defaults_to_thirty_days(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'is_published' => true]);
        VenueSubscription::query()->create(['user_id' => $user->id, 'venue_id' => $venue->id, 'status' => 'active']);
        Event::factory()->create([
            'venue_id' => $venue->id, 'type' => EventTypeEnum::TRAINING,
            'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHour(),
        ]);
        $provider = app(MyInterestsProvider::class);
        $this->assertSame(1, $provider->getData($user, [])->total);
        $data = $provider->getData($user, ['horizon_days' => 14]);
        $this->assertSame(0, $data->total);
        $this->assertSame(14, $data->summary['horizon_days']);
    }

    public function test_empty_wallet_has_zero_balances_and_no_transactions(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $data = app(WalletSummaryProvider::class)->getData($user, []);
        $this->assertSame(0, $data->total);
        $this->assertSame([], $data->items);
        $this->assertSame(0, $data->summary['total_balance_minor']);
        $this->assertSame('RUB', $data->summary['currency']);
    }
}
