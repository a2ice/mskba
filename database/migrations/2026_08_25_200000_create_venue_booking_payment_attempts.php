<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_bookings', function (Blueprint $table): void {
            $table->timestamp('payment_window_expires_at')->nullable()->after('payment_state');
        });

        Schema::create('venue_booking_payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('venue_booking_id')->unique()->constrained('venue_bookings')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('method', 32);
            $table->text('payment_instructions');
            $table->string('status', 32);
            $table->timestamp('window_opened_at');
            $table->timestamp('window_expires_at');
            $table->foreignId('opened_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->foreignId('claimed_by_actor_id')->nullable()->constrained('actors')->restrictOnDelete();
            $table->json('evidence_metadata')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('reviewed_by_actor_id')->nullable()->constrained('actors')->restrictOnDelete();
            $table->text('review_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'window_expires_at'], 'venue_booking_payment_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_booking_payment_attempts');
        Schema::table('venue_bookings', fn (Blueprint $table) => $table->dropColumn('payment_window_expires_at'));
    }
};
