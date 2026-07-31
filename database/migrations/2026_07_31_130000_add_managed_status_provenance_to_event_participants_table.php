<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->foreignId('status_changed_by_actor_id')
                ->nullable()
                ->after('responsibility_responded_at')
                ->constrained('actors')
                ->nullOnDelete();
            $table->timestampTz('status_changed_at')->nullable()->after('status_changed_by_actor_id');
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('status_changed_by_actor_id');
            $table->dropColumn('status_changed_at');
        });
    }
};
