<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_group_offers', function (Blueprint $table): void {
            // Existing group codes retain their audience and delivery-only behavior.
            $table->string('audience', 16)->default('GROUP');
            $table->boolean('auto_apply')->default(false);
            $table->json('booking_conditions')->nullable();
        });
        Schema::create('venue_offer_recipients', function (Blueprint $table): void {
            $table->foreignId('offer_id')->constrained('venue_group_offers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->primary(['offer_id', 'user_id']);
        });
        Schema::create('venue_offer_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('venue_group_offers');
            $table->foreignId('quote_id')->unique()->constrained('venue_booking_quotes');
            $table->foreignId('booking_id')->unique()->constrained('venue_bookings');
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedBigInteger('discount_minor');
            $table->timestampTz('created_at');
            $table->index(['offer_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_offer_redemptions');
        Schema::dropIfExists('venue_offer_recipients');
        Schema::table('venue_group_offers', fn (Blueprint $table) => $table->dropColumn(['audience', 'auto_apply', 'booking_conditions']));
    }
};
