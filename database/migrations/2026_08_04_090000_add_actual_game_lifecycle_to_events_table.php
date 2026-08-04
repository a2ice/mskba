<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->timestampTz('actual_started_at')->nullable()->after('ends_at');
            $table->foreignId('actual_started_by_actor_id')->nullable()->after('actual_started_at')
                ->constrained('actors')->nullOnDelete();
            $table->timestampTz('actual_ended_at')->nullable()->after('actual_started_by_actor_id');
            $table->foreignId('actual_ended_by_actor_id')->nullable()->after('actual_ended_at')
                ->constrained('actors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actual_ended_by_actor_id');
            $table->dropColumn('actual_ended_at');
            $table->dropConstrainedForeignId('actual_started_by_actor_id');
            $table->dropColumn('actual_started_at');
        });
    }
};
