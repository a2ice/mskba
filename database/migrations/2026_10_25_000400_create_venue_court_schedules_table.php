<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_court_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_court_id')->unique()->constrained('venue_courts')->cascadeOnDelete();
            $table->json('weekly_intervals')->nullable();
            $table->json('exceptions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_court_schedules');
    }
};
