<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_booking_conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('venue_booking_id')->unique()->constrained('venue_bookings')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('venue_booking_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('conversation_id')->constrained('venue_booking_conversations')->cascadeOnDelete();
            $table->foreignId('author_actor_id')->constrained('actors')->restrictOnDelete();
            $table->uuid('client_id');
            $table->string('type', 16)->default('text');
            $table->text('body')->nullable();
            $table->string('attachment_disk', 32)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 128)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['conversation_id', 'author_actor_id', 'client_id'], 'venue_booking_message_client_unique');
            $table->index(['conversation_id', 'id'], 'venue_booking_message_page');
        });

        Schema::create('venue_booking_conversation_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('venue_booking_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('last_read_message_id')->nullable()->constrained('venue_booking_messages')->nullOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id'], 'venue_booking_conversation_reader');
        });

        Schema::create('venue_booking_message_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('venue_booking_messages')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'recipient_user_id', 'channel'], 'venue_booking_message_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_booking_message_deliveries');
        Schema::dropIfExists('venue_booking_conversation_reads');
        Schema::dropIfExists('venue_booking_messages');
        Schema::dropIfExists('venue_booking_conversations');
    }
};
