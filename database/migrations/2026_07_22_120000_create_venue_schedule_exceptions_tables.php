<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_schedule_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_schedule_id')->constrained('venue_schedules')->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->unique(['venue_schedule_id', 'date']);
        });

        Schema::create('venue_schedule_exception_intervals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_schedule_exception_id')->constrained('venue_schedule_exceptions')->cascadeOnDelete();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['venue_schedule_exception_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_schedule_exception_intervals');
        Schema::dropIfExists('venue_schedule_exceptions');
    }
};
