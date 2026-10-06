<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $code = 'referral_second_level_user_confirmed';

        if (DB::table('rewards')->where('code', $code)->exists()) {
            return;
        }

        $now = now();

        $rewardId = DB::table('rewards')->insertGetId([
            'code' => $code,
            'name' => 'Подтверждённый пользователь второго уровня',
            'description' => 'Вознаграждение первоначальному пригласившему, когда подтверждённый им пользователь приводит нового пользователя, который также подтверждает аккаунт.',
            'mechanism_code' => $code,
            'is_enabled' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('reward_versions')->insert([
            'reward_id' => $rewardId,
            'version_number' => 1,
            'amount_minor' => 10000,
            'currency' => 'RUB',
            'mechanism_code' => $code,
            'conditions' => 'Цепочка A → B → C: A пригласил B, B подтвердил аккаунт, затем B пригласил C, и C подтвердил аккаунт. A получает одно вознаграждение второго уровня за C. Прямое вознаграждение B за C учитывается отдельным правилом. Более глубокие уровни этим правилом не создаются.',
            'recipient_description' => 'Пользователь A — первоначальный пригласивший подтверждённого пользователя B.',
            'trigger_description' => 'После подтверждения пользователя C, которого пригласил подтверждённый пользователь B.',
            'mechanism_parameters' => null,
            'valid_from' => $now,
            'valid_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $rewardId = DB::table('rewards')
            ->where('code', 'referral_second_level_user_confirmed')
            ->value('id');

        if ($rewardId === null) {
            return;
        }

        DB::table('reward_versions')->where('reward_id', $rewardId)->delete();
        DB::table('rewards')->where('id', $rewardId)->delete();
    }
};
