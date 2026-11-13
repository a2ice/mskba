<?php

namespace Tests\Feature\Rewards;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Rewards\Application\Data\RewardMechanismContext;
use App\Modules\Rewards\Application\Services\RewardEngine;
use App\Modules\Rewards\Domain\Models\Reward;
use App\Modules\Rewards\Domain\Models\RewardGrant;
use App\Modules\Rewards\Domain\Models\VenueRewardOverride;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VenueRentalBonusTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
    }

    public function test_paid_completed_booking_is_paid_once_and_custom_venue_nominal_applies(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $actor = app(CurrentActorResolver::class)->resolve($user, null);
        $this->assertNotNull($actor);
        $venue = Venue::factory()->create();
        $reward = Reward::query()->where('code', 'venue_rental_completed')->firstOrFail();
        $reward->update(['is_enabled' => true]);
        $reward->currentVersion()->firstOrFail()->update(['valid_from' => now()->subDays(10)]);

        $booking = $this->booking($venue, $user, (int) $actor->id);
        $engine = app(RewardEngine::class);
        $context = fn (VenueBooking $b) => new RewardMechanismContext(
            'venue_rental_completed',
            'booking:'.$b->id,
            ['booking_id' => (int) $b->id],
        );

        $this->assertNull($engine->process('venue_rental_completed', $context($booking)));
        $booking->forceFill(['payment_state' => VenueBookingPaymentState::CONFIRMED])->save();

        $first = $engine->process('venue_rental_completed', $context($booking));
        $this->assertNotNull($first);
        $this->assertSame(10000, $first->amount_minor);
        $this->assertSame($first->id, $engine->process('venue_rental_completed', $context($booking))->id);
        $this->assertSame(1, RewardGrant::query()->where('reward_id', $reward->id)->count());

        VenueRewardOverride::query()->create([
            'venue_id' => $venue->id,
            'reward_id' => $reward->id,
            'mode' => 'custom',
            'amount_minor' => 20000,
        ]);
        $secondBooking = $this->booking($venue, $user, (int) $actor->id);
        $secondBooking->forceFill(['payment_state' => VenueBookingPaymentState::CONFIRMED])->save();
        $second = $engine->process('venue_rental_completed', $context($secondBooking));
        $this->assertNotNull($second);
        $this->assertSame(20000, $second->amount_minor);

        $reward->update(['is_enabled' => false]);
        $thirdBooking = $this->booking($venue, $user, (int) $actor->id);
        $thirdBooking->forceFill(['payment_state' => VenueBookingPaymentState::CONFIRMED])->save();
        $this->assertNull($engine->process('venue_rental_completed', $context($thirdBooking)));
        $this->assertSame(2, RewardGrant::query()->where('reward_id', $reward->id)->count());
    }

    private function booking(Venue $venue, User $user, int $actorId): VenueBooking
    {
        return VenueBooking::query()->create([
            'public_id' => (string) Str::uuid(),
            'flow' => 'rental',
            'venue_id' => $venue->id,
            'created_by_actor_id' => $actorId,
            'requester_user_id' => $user->id,
            'status' => VenueBookingStatusEnum::CONFIRMED,
            'payment_state' => VenueBookingPaymentState::CLAIMED,
            'scope' => 'whole',
            'quote_snapshot' => ['pricing' => ['amount_minor' => 200000, 'currency' => 'RUB']],
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHour(),
            'confirmed_at' => now()->subDays(2),
        ]);
    }
}
