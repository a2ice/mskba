<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_booking_payment_attempts', function (Blueprint $table): void {
            $table->string('provider', 40)->default('external_manual')->after('method');
            $table->string('provider_reference')->nullable()->after('provider');
            $table->uuid('provider_idempotency_key')->nullable()->after('provider_reference');
            $table->string('merchant_reference')->nullable()->after('provider_idempotency_key');
            $table->json('provider_metadata')->nullable()->after('merchant_reference');
            $table->timestamp('provider_checked_at')->nullable()->after('provider_metadata');

            $table->unique(['provider', 'provider_reference'], 'venue_payment_provider_reference_unique');
            $table->unique(['provider', 'provider_idempotency_key'], 'venue_payment_provider_idempotency_unique');
            $table->index(['provider', 'status', 'provider_checked_at'], 'venue_payment_reconciliation');
        });

        Schema::create('venue_booking_payment_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('provider_event_id');
            $table->string('payload_hash', 64);
            $table->boolean('signature_valid')->default(false);
            $table->json('safe_payload')->nullable();
            $table->string('status', 24)->default('received');
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_booking_payment_webhooks');
        Schema::table('venue_booking_payment_attempts', function (Blueprint $table): void {
            $table->dropIndex('venue_payment_reconciliation');
            $table->dropUnique('venue_payment_provider_idempotency_unique');
            $table->dropUnique('venue_payment_provider_reference_unique');
            $table->dropColumn(['provider', 'provider_reference', 'provider_idempotency_key', 'merchant_reference', 'provider_metadata', 'provider_checked_at']);
        });
    }
};
