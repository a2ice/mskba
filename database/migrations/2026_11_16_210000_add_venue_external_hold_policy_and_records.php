<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_booking_policies', function (Blueprint $table): void {
            $table->boolean('external_hold_required')->default(false);
            $table->string('hold_verification_method', 32)->nullable();
            $table->text('hold_verification_note')->nullable();
            $table->timestamp('hold_verified_at')->nullable();
            $table->foreignId('hold_verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('buyer_postpay_allowed')->default(false);
            $table->boolean('agent_provider_postpay_allowed')->default(false);
            $table->boolean('agent_provider_self_attestation_allowed')->default(false);
        });

        Schema::create('venue_booking_external_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_booking_id')->unique()->constrained('venue_bookings')->cascadeOnDelete();
            $table->foreignId('venue_integration_source_id')->nullable()->constrained('venue_integration_sources')->nullOnDelete();
            $table->foreignId('confirmed_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->string('method', 32);
            $table->string('reference', 190)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_booking_external_holds');
        Schema::table('venue_booking_policies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hold_verified_by_user_id');
            $table->dropColumn([
                'external_hold_required', 'hold_verification_method',
                'hold_verification_note', 'hold_verified_at',
                'buyer_postpay_allowed', 'agent_provider_postpay_allowed',
                'agent_provider_self_attestation_allowed',
            ]);
        });
    }
};
