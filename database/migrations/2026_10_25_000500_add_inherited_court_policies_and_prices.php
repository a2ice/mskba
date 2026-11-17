<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->boolean('requires_booking')->nullable();
        });
        Schema::table('venue_courts', function (Blueprint $table): void {
            $table->string('operational_status', 30)->nullable();
            $table->boolean('requires_payment')->nullable();
            $table->boolean('requires_booking')->nullable();
            $table->boolean('requires_booking_approval')->nullable();
            $table->boolean('rental_enabled')->nullable();
            $table->unsignedBigInteger('whole_price_per_step_minor')->nullable();
            $table->unsignedBigInteger('half_price_per_step_minor')->nullable();
        });
        Schema::create('venue_court_slot_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_court_id')->constrained('venue_courts')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->unsignedBigInteger('whole_price_per_step_minor')->nullable();
            $table->unsignedBigInteger('half_price_per_step_minor')->nullable();
            $table->timestamps();
            $table->unique(['venue_court_id', 'day_of_week', 'starts_at'], 'court_slot_price_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_court_slot_prices');
        Schema::table('venue_courts', function (Blueprint $table): void {
            $table->dropColumn(['operational_status', 'requires_payment', 'requires_booking',
                'requires_booking_approval', 'rental_enabled',
                'whole_price_per_step_minor', 'half_price_per_step_minor']);
        });
        Schema::table('venues', fn (Blueprint $table) => $table->dropColumn('requires_booking'));
    }
};
