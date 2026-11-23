<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_court_manual_slot_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_court_id')->constrained('venue_courts')->cascadeOnDelete();
            // Exactly one of date or day_of_week is set by the tariff-grid service.
            // Separate unique keys also work on PostgreSQL with the nullable column.
            $table->date('date')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->time('starts_at');
            $table->unsignedBigInteger('whole_price_per_step_minor')->nullable();
            $table->unsignedBigInteger('half_price_per_step_minor')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['venue_court_id', 'date', 'starts_at'], 'court_manual_calendar_price_unique');
            $table->unique(['venue_court_id', 'day_of_week', 'starts_at'], 'court_manual_weekly_price_unique');
            $table->index(['venue_court_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_court_manual_slot_prices');
    }
};
