<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_courts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('minimum_duration_minutes')->nullable();
            $table->unsignedSmallInteger('maximum_duration_minutes')->nullable();
            $table->unsignedSmallInteger('time_step_minutes')->nullable();
            $table->unsignedInteger('minimum_lead_time_minutes')->nullable();
            $table->unsignedSmallInteger('maximum_advance_days')->nullable();
        });

        Schema::create('venue_calendar_slot_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->date('date');
            $table->time('starts_at');
            $table->unsignedBigInteger('whole_price_per_step_minor')->nullable();
            $table->unsignedBigInteger('half_price_per_step_minor')->nullable();
            $table->timestamps();

            $table->unique(['venue_id', 'date', 'starts_at'], 'venue_calendar_price_unique');
            $table->index(['venue_id', 'date']);
        });

        Schema::create('venue_court_calendar_slot_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_court_id')->constrained('venue_courts')->cascadeOnDelete();
            $table->date('date');
            $table->time('starts_at');
            $table->unsignedBigInteger('whole_price_per_step_minor')->nullable();
            $table->unsignedBigInteger('half_price_per_step_minor')->nullable();
            $table->timestamps();

            $table->unique(['venue_court_id', 'date', 'starts_at'], 'venue_court_calendar_price_unique');
            $table->index(['venue_court_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_court_calendar_slot_prices');
        Schema::dropIfExists('venue_calendar_slot_prices');
        Schema::table('venue_courts', function (Blueprint $table): void {
            $table->dropColumn([
                'minimum_duration_minutes',
                'maximum_duration_minutes',
                'time_step_minutes',
                'minimum_lead_time_minutes',
                'maximum_advance_days',
            ]);
        });
    }
};
