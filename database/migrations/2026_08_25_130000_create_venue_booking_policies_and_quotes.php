<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_booking_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->boolean('is_enabled')->default(false);
            $table->boolean('allows_whole')->default(true);
            $table->boolean('allows_halves')->default(false);
            $table->unsignedSmallInteger('minimum_duration_minutes');
            $table->unsignedSmallInteger('maximum_duration_minutes');
            $table->unsignedSmallInteger('time_step_minutes');
            $table->unsignedInteger('minimum_lead_time_minutes')->default(0);
            $table->unsignedSmallInteger('maximum_advance_days')->default(90);
            $table->char('currency', 3);
            $table->unsignedBigInteger('whole_price_per_step_minor');
            $table->unsignedBigInteger('half_price_per_step_minor')->nullable();
            $table->unsignedSmallInteger('hold_duration_minutes');
            $table->boolean('requires_payment')->default(true);
            $table->unsignedSmallInteger('payment_window_minutes')->nullable();
            $table->unsignedSmallInteger('quote_validity_minutes')->default(15);
            $table->unsignedInteger('cancellation_before_minutes')->nullable();
            $table->foreignId('published_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->boolean('active_marker')->nullable()->default(true);
            $table->timestamps();

            $table->unique(['venue_id', 'version']);
            $table->unique(['venue_id', 'active_marker'], 'venue_booking_policies_one_active');
        });

        Schema::create('venue_booking_quotes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_version_id')->constrained('venue_booking_policies')->restrictOnDelete();
            $table->foreignId('quoted_for_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('hold_duration_minutes');
            $table->unsignedSmallInteger('payment_window_minutes')->nullable();
            $table->boolean('requires_payment');
            $table->json('snapshot');
            $table->timestamp('valid_until');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['venue_id', 'starts_at', 'ends_at']);
            $table->index('valid_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_booking_quotes');
        Schema::dropIfExists('venue_booking_policies');
    }
};
