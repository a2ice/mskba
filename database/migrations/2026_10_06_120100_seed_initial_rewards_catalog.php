<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rewards = [
            [
                'code' => 'referral_user_confirmed',
                'name' => 'Подтверждённый приглашённый пользователь',
                'description' => 'Вознаграждение пригласившему после подтверждения приглашённого пользователя.',
                'mechanism_code' => 'referral_user_confirmed',
                'amount_minor' => 30000,
                'conditions' => 'Одно начисление пригласившему за одного подтверждённого приглашённого пользователя. Детали attribution и критерий подтверждения уточняются при реализации механизма.',
                'recipient_description' => 'Пользователь, которому атрибутировано приглашение.',
                'trigger_description' => 'После подтверждения приглашённого пользователя.',
            ],
            [
                'code' => 'venue_first_approval',
                'name' => 'Первая успешная модерация новой площадки',
                'description' => 'Вознаграждение за добавление новой площадки после её первой успешной модерации.',
                'mechanism_code' => 'venue_first_approval',
                'amount_minor' => 10000,
                'conditions' => 'Одно начисление за первую успешную модерацию новой площадки. Повторная модерация и изменение подтверждённой площадки не создают новую награду.',
                'recipient_description' => 'Предполагаемый получатель — автор добавления площадки; правило уточняется при реализации механизма.',
                'trigger_description' => 'После первой успешной модерации новой площадки.',
            ],
        ];

        foreach ($rewards as $reward) {
            $rewardId = DB::table('rewards')->insertGetId([
                'code' => $reward['code'],
                'name' => $reward['name'],
                'description' => $reward['description'],
                'mechanism_code' => $reward['mechanism_code'],
                'is_enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('reward_versions')->insert([
                'reward_id' => $rewardId,
                'version_number' => 1,
                'amount_minor' => $reward['amount_minor'],
                'currency' => 'RUB',
                'mechanism_code' => $reward['mechanism_code'],
                'conditions' => $reward['conditions'],
                'recipient_description' => $reward['recipient_description'],
                'trigger_description' => $reward['trigger_description'],
                'mechanism_parameters' => null,
                'valid_from' => $now,
                'valid_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $rewardIds = DB::table('rewards')
            ->whereIn('code', ['referral_user_confirmed', 'venue_first_approval'])
            ->pluck('id');

        DB::table('reward_versions')->whereIn('reward_id', $rewardIds)->delete();
        DB::table('rewards')->whereIn('id', $rewardIds)->delete();
    }
};
