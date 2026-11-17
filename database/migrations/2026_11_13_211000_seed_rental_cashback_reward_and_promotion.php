<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rewardId = DB::table('rewards')->where('code', 'venue_rental_completed')->value('id');

        if ($rewardId === null) {
            $rewardId = DB::table('rewards')->insertGetId([
                'code' => 'venue_rental_completed',
                'name' => 'Кешбэк за завершённую аренду площадки',
                'description' => 'Фиксированное бонусное начисление арендатору после оплаченной завершённой аренды.',
                'mechanism_code' => 'venue_rental_completed',
                // Remains disabled until end-to-end refund/booking acceptance.
                'is_enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('reward_versions')->insert([
                'reward_id' => $rewardId,
                'version_number' => 1,
                'amount_minor' => 10000,
                'currency' => 'RUB',
                'mechanism_code' => 'venue_rental_completed',
                'conditions' => 'Один раз за подтверждённую оплаченную завершённую аренду, с учётом площадки.',
                'recipient_description' => 'Пользователь, оплативший аренду.',
                'trigger_description' => 'После окончания подтверждённой оплаченной аренды.',
                'mechanism_parameters' => null,
                'valid_from' => $now,
                'valid_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! DB::table('promotions')->where('code', 'reward_venue_rental_completed')->exists()) {
            $id = DB::table('promotions')->insertGetId([
                'code' => 'reward_venue_rental_completed',
                'type' => 'rewardable',
                'reward_id' => $rewardId,
                'title' => 'Арендуй зал — получи бонусы',
                'description' => 'После завершённой оплаченной аренды вам начислят бонусы на кошелёк.',
                'cta_label' => 'Арендовать зал',
                'cta_url' => '/venues',
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (['avatar_topup', 'account_wallet', 'venue_page', 'venue_slots'] as $placement) {
                DB::table('promotion_placements')->insert([
                    'promotion_id' => $id,
                    'placement_code' => $placement,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $rewardId = DB::table('rewards')->where('code', 'venue_rental_completed')->value('id');
        if ($rewardId !== null && DB::table('reward_grants')->where('reward_id', $rewardId)->exists()) {
            throw new \RuntimeException('Нельзя откатить начисленный кешбэк: финансовая история неизменяема.');
        }

        $promotionId = DB::table('promotions')->where('code', 'reward_venue_rental_completed')->value('id');
        if ($promotionId !== null) {
            DB::table('promotion_placements')->where('promotion_id', $promotionId)->delete();
            DB::table('promotions')->where('id', $promotionId)->delete();
        }

        $rewardId = DB::table('rewards')->where('code', 'venue_rental_completed')->value('id');
        if ($rewardId !== null) {
            DB::table('venue_reward_overrides')->where('reward_id', $rewardId)->delete();
            DB::table('reward_versions')->where('reward_id', $rewardId)->delete();
            DB::table('rewards')->where('id', $rewardId)->delete();
        }
    }
};
