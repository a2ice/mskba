<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_venue_rental_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coordination_id')->constrained('venue_rental_coordinations')->cascadeOnDelete();
            $table->foreignId('venue_booking_id')->nullable()->constrained('venue_bookings')->restrictOnDelete();
            $table->foreignId('chat_id')->constrained('telegram_chats')->cascadeOnDelete();
            $table->bigInteger('message_id')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->text('last_error')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['coordination_id', 'chat_id'], 'telegram_rental_coordination_chat_unique');
            $table->unique(['chat_id', 'message_id'], 'telegram_rental_chat_message_unique');
        });

        Schema::create('telegram_venue_rental_updates', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('update_id')->nullable()->unique();
            $table->string('callback_id', 160)->unique();
            $table->foreignId('coordination_id')->nullable()->constrained('venue_rental_coordinations')->nullOnDelete();
            $table->unsignedBigInteger('telegram_user_id')->nullable()->index();
            $table->string('action', 24)->nullable();
            $table->string('status', 24)->default('processing')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_venue_rental_updates');
        Schema::dropIfExists('telegram_venue_rental_publications');
    }
};
