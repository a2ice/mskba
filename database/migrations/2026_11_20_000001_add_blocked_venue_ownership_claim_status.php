<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE venue_ownership_claims DROP CONSTRAINT IF EXISTS venue_ownership_claims_status_check');
            DB::statement("ALTER TABLE venue_ownership_claims ADD CONSTRAINT venue_ownership_claims_status_check CHECK (status IN ('draft','pending','approved','rejected','blocked','cancelled'))");
        }
    }

    public function down(): void
    {
        DB::table('venue_ownership_claims')->where('status', 'blocked')->update(['status' => 'rejected']);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE venue_ownership_claims DROP CONSTRAINT IF EXISTS venue_ownership_claims_status_check');
            DB::statement("ALTER TABLE venue_ownership_claims ADD CONSTRAINT venue_ownership_claims_status_check CHECK (status IN ('draft','pending','approved','rejected','cancelled'))");
        }
    }
};
