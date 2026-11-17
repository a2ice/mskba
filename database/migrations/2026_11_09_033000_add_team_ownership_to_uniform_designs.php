<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uniform_designs', function (Blueprint $table): void {
            // Exactly one current design per team. Personal designs keep team_id NULL.
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->unique('team_id');
        });
    }

    public function down(): void
    {
        Schema::table('uniform_designs', function (Blueprint $table): void {
            $table->dropUnique(['team_id']);
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
