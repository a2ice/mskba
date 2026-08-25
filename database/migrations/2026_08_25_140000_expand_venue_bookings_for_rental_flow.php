<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE venue_bookings DROP CONSTRAINT IF EXISTS venue_bookings_status_check');
        }

        Schema::table('venue_bookings', function (Blueprint $table): void {
            $table->string('status', 32)->change();
            $table->foreignId('event_id')->nullable()->change();
            $table->uuid('public_id')->nullable()->unique()->after('id');
            $table->string('flow', 16)->default('legacy')->after('public_id');
            $table->foreignId('requester_user_id')->nullable()->after('created_by_actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('policy_version_id')->nullable()->after('requester_user_id')->constrained('venue_booking_policies')->restrictOnDelete();
            $table->foreignId('quote_id')->nullable()->unique()->after('policy_version_id')->constrained('venue_booking_quotes')->restrictOnDelete();
            $table->json('quote_snapshot')->nullable()->after('quote_id');
            $table->string('payment_state', 32)->default('not_required')->after('quote_snapshot');
            $table->timestamp('hold_expires_at')->nullable()->after('ends_at');
            $table->timestamp('effective_protection_until')->nullable()->after('hold_expires_at');
            $table->unsignedBigInteger('optimistic_version')->default(1)->after('effective_protection_until');
            $table->timestamp('requested_at')->nullable()->after('optimistic_version');
            $table->timestamp('held_at')->nullable()->after('requested_at');
            $table->timestamp('confirmed_at')->nullable()->after('held_at');
            $table->timestamp('terminal_at')->nullable()->after('confirmed_at');

            $table->index(['venue_id', 'flow', 'status', 'starts_at'], 'venue_bookings_rental_lookup');
            $table->index(['status', 'effective_protection_until'], 'venue_bookings_expiry_lookup');
        });

        Schema::create('venue_booking_parties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_booking_id')->constrained('venue_bookings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 32);
            $table->timestamps();

            $table->unique(['venue_booking_id', 'user_id', 'role'], 'venue_booking_parties_unique');
            $table->index(['user_id', 'role']);
        });

        Schema::create('venue_booking_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_booking_id')->constrained('venue_bookings')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_id')->nullable()->constrained('actors')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('booking_version');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['venue_booking_id', 'booking_version']);
            $table->index(['venue_booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_booking_transitions');
        Schema::dropIfExists('venue_booking_parties');

        Schema::table('venue_bookings', function (Blueprint $table): void {
            $table->dropIndex('venue_bookings_expiry_lookup');
            $table->dropIndex('venue_bookings_rental_lookup');
            $table->dropUnique(['quote_id']);
            $table->dropConstrainedForeignId('quote_id');
            $table->dropConstrainedForeignId('policy_version_id');
            $table->dropConstrainedForeignId('requester_user_id');
            $table->dropUnique(['public_id']);
            $table->dropColumn([
                'public_id', 'flow', 'quote_snapshot', 'payment_state',
                'hold_expires_at', 'effective_protection_until', 'optimistic_version',
                'requested_at', 'held_at', 'confirmed_at', 'terminal_at',
            ]);
            $table->foreignId('event_id')->nullable(false)->change();
        });
    }
};
