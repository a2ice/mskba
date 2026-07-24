<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE event_participants DROP CONSTRAINT IF EXISTS event_participants_status_check');
            DB::statement("ALTER TABLE event_participants ADD CONSTRAINT event_participants_status_check CHECK (status IN ('confirmed', 'tentative', 'left'))");
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE event_participants MODIFY status ENUM('confirmed', 'tentative', 'left') NOT NULL DEFAULT 'confirmed'");
        }
    }

    public function down(): void
    {
        DB::table('event_participants')
            ->where('status', 'tentative')
            ->update([
                'status' => 'left',
                'left_at' => now(),
            ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE event_participants DROP CONSTRAINT IF EXISTS event_participants_status_check');
            DB::statement("ALTER TABLE event_participants ADD CONSTRAINT event_participants_status_check CHECK (status IN ('confirmed', 'left'))");
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE event_participants MODIFY status ENUM('confirmed', 'left') NOT NULL DEFAULT 'confirmed'");
        }
    }
};
