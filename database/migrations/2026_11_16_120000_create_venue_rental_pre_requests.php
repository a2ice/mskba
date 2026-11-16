<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_rental_pre_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('venue_id')->constrained();
            $table->foreignId('venue_court_id')->constrained();
            $table->foreignId('requester_user_id')->constrained('users');
            $table->foreignId('created_by_actor_id')->constrained('actors');
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->string('scope', 20);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->text('note')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['requester_user_id', 'idempotency_key'], 'rental_pre_request_idempotency');
            $table->index(['venue_id', 'status']);
        });
        Schema::table('venue_bookings', function (Blueprint $table): void {
            $table->foreignId('pre_request_id')->nullable()->constrained('venue_rental_pre_requests');
            $table->foreignId('created_by_representative_id')->nullable()->constrained('actors');
            $table->timestamp('proposal_expires_at')->nullable();
            $table->timestamp('proposal_accepted_at')->nullable();
        });
        Schema::table('venue_booking_conversations', function (Blueprint $table): void {
            // A pre-request opens its conversation before a booking exists.
            $table->foreignId('venue_booking_id')->nullable()->change();
            $table->foreignId('pre_request_id')->nullable()->unique()->constrained('venue_rental_pre_requests');
        });
    }

    public function down(): void
    {
        // Pre-request-only conversations have no booking ID; remove them before
        // restoring the legacy NOT NULL constraint on rollback.
        \Illuminate\Support\Facades\DB::table('venue_booking_conversations')->whereNull('venue_booking_id')->delete();
        Schema::table('venue_booking_conversations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pre_request_id');
            $table->foreignId('venue_booking_id')->change();
        });
        Schema::table('venue_bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pre_request_id');
            $table->dropConstrainedForeignId('created_by_representative_id');
            $table->dropColumn(['proposal_expires_at', 'proposal_accepted_at']);
        });
        Schema::dropIfExists('venue_rental_pre_requests');
    }
};
