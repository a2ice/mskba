<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_schedule_slot_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->unsignedBigInteger('whole_price_per_step_minor')->nullable();
            $table->unsignedBigInteger('half_price_per_step_minor')->nullable();
            $table->timestamps();

            $table->unique(['venue_id', 'day_of_week', 'starts_at'], 'venue_slot_prices_unique');
            $table->index(['venue_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_schedule_slot_prices');
    }
};
