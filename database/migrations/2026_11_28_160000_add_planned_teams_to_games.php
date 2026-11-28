<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->foreignId('planned_team_a_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('planned_team_b_id')->nullable()->constrained('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('planned_team_a_id');
            $table->dropConstrainedForeignId('planned_team_b_id');
        });
    }
};
