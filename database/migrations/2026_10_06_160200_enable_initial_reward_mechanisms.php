<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODES = [
        'referral_user_confirmed',
        'referral_second_level_user_confirmed',
        'venue_first_approval',
    ];

    public function up(): void
    {
        DB::table('rewards')
            ->whereIn('code', self::CODES)
            ->update([
                'is_enabled' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('rewards')
            ->whereIn('code', self::CODES)
            ->update([
                'is_enabled' => false,
                'updated_at' => now(),
            ]);
    }
};
