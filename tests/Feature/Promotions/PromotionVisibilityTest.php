<?php

namespace Tests\Feature\Promotions;

use App\Modules\Promotions\Application\Queries\ResolveActivePromotions;
use App\Modules\Promotions\Domain\Enums\PromotionType;
use App\Modules\Promotions\Domain\Models\Promotion;
use App\Modules\Rewards\Domain\Models\Reward;
use App\Modules\Rewards\Domain\Models\VenueRewardOverride;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

final class PromotionVisibilityTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
    }

    public function test_seeded_promotions_wrap_existing_rewards_and_inherit_text(): void
    {
        $offers = app(ResolveActivePromotions::class)->forPlacement('avatar_topup');
        $this->assertCount(3, $offers);
        $this->assertEqualsCanonicalizing([
            'referral_user_confirmed',
            'referral_second_level_user_confirmed',
            'venue_first_approval',
        ], array_column($offers, 'reward_code'));

        $promotion = Promotion::query()->where('code', 'reward_venue_first_approval')->firstOrFail();
        $placement = $promotion->placements()->where('placement_code', 'avatar_topup')->firstOrFail();
        $placement->update(['title_override' => 'Специальное название', 'description_override' => 'Специальное описание']);
        $offer = collect(app(ResolveActivePromotions::class)->forPlacement('avatar_topup'))
            ->firstWhere('reward_code', 'venue_first_approval');
        $this->assertSame('Специальное название', $offer['title']);
        $this->assertSame('Специальное описание', $offer['description']);

        $placement->update(['title_override' => null, 'description_override' => null]);
        $offer = collect(app(ResolveActivePromotions::class)->forPlacement('avatar_topup'))
            ->firstWhere('reward_code', 'venue_first_approval');
        $this->assertSame($promotion->title, $offer['title']);
    }

    public function test_promotion_and_reward_must_both_be_active_and_in_date_range(): void
    {
        $code = 'referral_user_confirmed';
        $promotion = Promotion::query()->where('code', 'reward_'.$code)->firstOrFail();
        $reward = Reward::query()->where('code', $code)->firstOrFail();
        $offered = fn (): bool => in_array($code,
            array_column(app(ResolveActivePromotions::class)->forPlacement('avatar_topup'), 'reward_code'), true);

        $this->assertTrue($offered());
        $promotion->update(['is_enabled' => false]);
        $this->assertFalse($offered());
        $promotion->update(['is_enabled' => true, 'ends_at' => now()->subMinute()]);
        $this->assertFalse($offered());
        $promotion->update(['ends_at' => null, 'starts_at' => now()->addDay()]);
        $this->assertFalse($offered());
        $promotion->update(['starts_at' => null]);
        $reward->update(['is_enabled' => false]);
        $this->assertFalse($offered());
    }

    public function test_simple_offer_is_public_without_reward_and_multi_is_reserved(): void
    {
        $simple = Promotion::query()->create([
            'code' => 'simple_news',
            'type' => PromotionType::SIMPLE,
            'title' => 'Новость MSKBA',
            'description' => 'Актуальная информация',
            'is_enabled' => true,
        ]);
        $simple->placements()->create(['placement_code' => 'avatar_topup']);
        $find = fn () => collect(app(ResolveActivePromotions::class)->forPlacement('avatar_topup'))
            ->firstWhere('promotion_code', 'simple_news');
        $this->assertSame('Новость MSKBA', $find()['title']);
        $this->assertNull($find()['amount_minor']);
        $simple->update(['type' => PromotionType::MULTI_REWARDABLE]);
        $this->assertNull($find());
    }

    public function test_rental_bonus_is_hidden_until_enabled_and_venue_overrides_apply(): void
    {
        $reward = Reward::query()->where('code', 'venue_rental_completed')->firstOrFail();
        $venue = Venue::factory()->create();

        $find = fn () => collect(app(ResolveActivePromotions::class)->forPlacement('venue_slots', (int) $venue->id))
            ->firstWhere('reward_code', 'venue_rental_completed');

        $this->assertFalse($reward->is_enabled);
        $this->assertNull($find());
        $reward->update(['is_enabled' => true]);
        $this->assertSame(10000, $find()['amount_minor']);

        $override = VenueRewardOverride::query()->create([
            'venue_id' => $venue->id,
            'reward_id' => $reward->id,
            'mode' => 'custom',
            'amount_minor' => 20000,
        ]);
        $this->assertSame(20000, $find()['amount_minor']);
        $override->update(['amount_minor' => 0]);
        $this->assertNull($find());
        $override->update(['mode' => 'disabled', 'amount_minor' => null]);
        $this->assertNull($find());
        $override->update(['mode' => 'inherit']);
        $this->assertSame(10000, $find()['amount_minor']);
        $reward->update(['is_enabled' => false]);
        $this->assertNull($find());
    }
}
