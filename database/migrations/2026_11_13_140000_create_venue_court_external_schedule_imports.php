<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_court_external_schedule_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('venue_court_id')->unique()->constrained('venue_courts')->cascadeOnDelete();
            $table->foreignId('venue_integration_source_id')->constrained('venue_integration_sources')->restrictOnDelete();
            $table->foreignId('external_availability_snapshot_id')->constrained('external_availability_snapshots')->restrictOnDelete();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('first_date');
            $table->date('last_date');
            $table->dateTimeTz('applied_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_court_external_schedule_imports');
    }
};
