<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_section_team', function (Blueprint $table): void {
            $table->foreignId('sports_section_id')->constrained('sports_sections')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['sports_section_id', 'team_id']);
            $table->index(['team_id', 'sports_section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_section_team');
    }
};
