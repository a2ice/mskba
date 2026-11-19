<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            // An unscheduled event is an organizer-visible draft, never a
            // published entry or booking. Existing event timestamps are kept.
            $table->timestampTz('starts_at')->nullable()->change();
            $table->timestampTz('ends_at')->nullable()->change();
            $table->json('proposed_time_options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('proposed_time_options');
            // Reverting nullability requires resolving unscheduled drafts first.
        });
    }
};
