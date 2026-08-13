<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_admissions', function (Blueprint $table): void {
            $table->timestampTz('blocked_at')->nullable()->after('responded_at');
            $table->foreignId('blocked_by_actor_id')->nullable()->after('blocked_at')->constrained('actors')->nullOnDelete();
            $table->index(['tournament_id', 'user_id', 'blocked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tournament_admissions', function (Blueprint $table): void {
            $table->dropIndex(['tournament_id', 'user_id', 'blocked_at']);
            $table->dropConstrainedForeignId('blocked_by_actor_id');
            $table->dropColumn('blocked_at');
        });
    }
};
