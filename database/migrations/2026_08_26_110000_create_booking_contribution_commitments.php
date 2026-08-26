<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_contribution_commitments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('venue_booking_id')->constrained('venue_bookings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 16);
            $table->boolean('active_marker')->nullable();
            $table->boolean('share_with_organizer')->default(false);
            $table->string('payment_intent_reference')->nullable();
            $table->timestamp('committed_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->unique(['venue_booking_id', 'user_id', 'active_marker'], 'booking_contribution_one_active');
            $table->index(['venue_booking_id', 'status'], 'booking_contribution_summary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_contribution_commitments');
    }
};
