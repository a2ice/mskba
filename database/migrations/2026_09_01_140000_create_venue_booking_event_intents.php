<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_booking_event_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_booking_id')->unique()->constrained('venue_bookings')->cascadeOnDelete();
            $table->foreignId('created_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->json('event_payload');
            $table->json('telegram_chat_ids')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_booking_event_intents');
    }
};
