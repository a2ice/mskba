<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_booking_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('actors')->restrictOnDelete();
            $table->foreignId('venue_booking_id')->nullable()->constrained('venue_bookings')->cascadeOnDelete();
            $table->string('command_name', 80);
            $table->uuid('idempotency_key');
            $table->uuid('correlation_id');
            $table->char('payload_hash', 64);
            $table->string('status', 16)->default('processing');
            $table->json('response')->nullable();
            $table->timestamps();

            $table->unique(['actor_id', 'idempotency_key'], 'venue_booking_command_actor_key_unique');
            $table->index(['status', 'created_at']);
        });

        Schema::table('venue_booking_transitions', function (Blueprint $table): void {
            $table->foreignId('command_receipt_id')->nullable()->unique()->after('actor_id')
                ->constrained('venue_booking_command_receipts')->restrictOnDelete();
            $table->uuid('correlation_id')->nullable()->after('command_receipt_id');
        });

        Schema::create('venue_booking_outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('message_id')->unique();
            $table->foreignId('venue_booking_id')->constrained('venue_bookings')->cascadeOnDelete();
            $table->string('event_type', 160);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
        });

        Schema::create('venue_booking_event_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->string('consumer', 120);
            $table->uuid('message_id');
            $table->timestamp('consumed_at');

            $table->unique(['consumer', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_booking_event_consumptions');
        Schema::dropIfExists('venue_booking_outbox_messages');
        Schema::table('venue_booking_transitions', function (Blueprint $table): void {
            $table->dropUnique(['command_receipt_id']);
            $table->dropConstrainedForeignId('command_receipt_id');
            $table->dropColumn('correlation_id');
        });
        Schema::dropIfExists('venue_booking_command_receipts');
    }
};
