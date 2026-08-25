<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_booking_policies', function (Blueprint $table): void {
            $table->boolean('allows_hold_extension')->default(false)->after('hold_duration_minutes');
            $table->unsignedSmallInteger('maximum_hold_extension_minutes')->nullable()->after('allows_hold_extension');
        });

        Schema::create('venue_booking_extension_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('venue_booking_id')->constrained('venue_bookings')->cascadeOnDelete();
            $table->foreignId('requested_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->timestamp('previous_deadline_at');
            $table->timestamp('requested_until');
            $table->text('reason');
            $table->string('status', 16);
            $table->boolean('active_marker')->nullable();
            $table->foreignId('reviewed_by_actor_id')->nullable()->constrained('actors')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['venue_booking_id', 'active_marker'], 'venue_booking_extension_active_unique');
            $table->index(['venue_booking_id', 'requested_at'], 'venue_booking_extension_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_booking_extension_requests');

        Schema::table('venue_booking_policies', function (Blueprint $table): void {
            $table->dropColumn(['allows_hold_extension', 'maximum_hold_extension_minutes']);
        });
    }
};
