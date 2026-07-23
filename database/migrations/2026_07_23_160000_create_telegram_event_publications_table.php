<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_event_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained('events')->cascadeOnDelete();
            $table->string('chat_id');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_event_publications');
    }
};
