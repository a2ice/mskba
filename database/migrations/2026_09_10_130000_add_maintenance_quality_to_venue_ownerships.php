<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_ownerships', function (Blueprint $table): void {
            $table->boolean('maintenance_commitment_accepted')->default(false)->after('active_marker');
            $table->unsignedSmallInteger('maintenance_score')->default(0)->after('maintenance_commitment_accepted');
            $table->text('maintenance_comment')->nullable()->after('maintenance_score');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE venue_ownerships
                ADD CONSTRAINT venue_ownerships_maintenance_score_check
                CHECK (maintenance_score >= 0 AND maintenance_score <= 100 AND maintenance_score % 10 = 0)
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE venue_ownerships DROP CONSTRAINT IF EXISTS venue_ownerships_maintenance_score_check');
        }

        Schema::table('venue_ownerships', function (Blueprint $table): void {
            $table->dropColumn([
                'maintenance_commitment_accepted',
                'maintenance_score',
                'maintenance_comment',
            ]);
        });
    }
};
