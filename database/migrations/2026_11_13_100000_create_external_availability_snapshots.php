<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historical observations are append-only; never overwrite the venue's
        // schedule, price policies, or confirmed bookings with external data.
        Schema::create('external_availability_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_integration_source_id')
                ->constrained('venue_integration_sources')->restrictOnDelete();
            $table->foreignId('venue_id')->constrained('venues')->restrictOnDelete();
            $table->foreignId('venue_court_id')->constrained('venue_courts')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('external_court_id', 190);
            $table->string('provider', 48);
            $table->string('timezone', 64);
            $table->string('parser_version', 32);
            $table->string('checksum', 64);
            $table->string('snapshot_key', 64)->unique();
            $table->string('currency', 3);
            $table->unsignedSmallInteger('step_minutes');
            $table->unsignedSmallInteger('minimum_minutes');
            $table->unsignedInteger('freshness_ttl_seconds');
            $table->boolean('complete');
            $table->dateTimeTz('observed_at');
            $table->dateTimeTz('coverage_start_at');
            $table->dateTimeTz('coverage_end_at');
            $table->json('diagnostic')->nullable(); // Aggregates only; never raw HTML/credentials.
            $table->timestampTz('created_at')->useCurrent();
            $table->index(
                ['venue_integration_source_id', 'venue_court_id', 'observed_at'],
                'eas_source_court_time_idx',
            );
            $table->index(['venue_court_id', 'observed_at'], 'eas_court_time_idx');
        });

        Schema::create('external_availability_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_availability_snapshot_id')
                ->constrained('external_availability_snapshots')->cascadeOnDelete();
            $table->date('local_date');
            $table->dateTimeTz('starts_at'); // UTC instants, not local wall times.
            $table->dateTimeTz('ends_at');
            $table->string('status', 16);
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->unsignedSmallInteger('price_unit_minutes');
            $table->boolean('day_complete')->default(false);
            $table->unique(
                ['external_availability_snapshot_id', 'starts_at'],
                'eas_slot_unique',
            );
            $table->index(['starts_at', 'ends_at'], 'eas_slot_span_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_availability_slots');
        Schema::dropIfExists('external_availability_snapshots');
    }
};
