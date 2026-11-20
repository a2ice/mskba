<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_rental_payment_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->string('label', 120);
            // The Laravel encrypted:array cast protects the phone/card at rest.
            $table->text('details');
            $table->timestamps();
            $table->index(['owner_user_id', 'venue_id']);
        });

        Schema::table('venue_rental_invoices', function (Blueprint $table): void {
            $table->text('payment_details')->nullable();
            $table->string('payment_reference', 190)->nullable();
            $table->text('payment_claim_note')->nullable();
        });

        Schema::table('venue_booking_messages', function (Blueprint $table): void {
            $table->foreignId('rental_invoice_id')->nullable()
                ->constrained('venue_rental_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('venue_booking_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rental_invoice_id');
        });
        Schema::table('venue_rental_invoices', function (Blueprint $table): void {
            $table->dropColumn(['payment_details', 'payment_reference', 'payment_claim_note']);
        });
        Schema::dropIfExists('venue_rental_payment_profiles');
    }
};
