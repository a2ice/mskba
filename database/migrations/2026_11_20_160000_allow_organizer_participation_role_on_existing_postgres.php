<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // The original Laravel enum migration created a PostgreSQL CHECK
        // constraint before organizer was added to the application enum.
        // Fresh databases already allow it, but existing databases do not.
        DB::statement('ALTER TABLE user_participation_roles DROP CONSTRAINT IF EXISTS user_participation_roles_role_check');
        DB::statement("ALTER TABLE user_participation_roles ADD CONSTRAINT user_participation_roles_role_check CHECK (role IN ('player', 'coach', 'referee', 'statistician', 'media', 'venue_related', 'organizer'))");
    }

    public function down(): void
    {
        // The application enum already contains organizer before this repair.
        // Do not invalidate or delete legitimately assigned organizer roles.
    }
};
