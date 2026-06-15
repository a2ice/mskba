<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_schedules', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('venue_id')
                ->constrained('venues')
                ->cascadeOnDelete();
            $table->string('timezone', 64)->default('Europe/Moscow');
            $table->timestamps();

            $table->unique('venue_id');
        });

        Schema::create('venue_schedule_intervals', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('venue_schedule_id')
                ->constrained('venue_schedules')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['venue_schedule_id', 'day_of_week', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_schedule_intervals');
        Schema::dropIfExists('venue_schedules');
    }
};
