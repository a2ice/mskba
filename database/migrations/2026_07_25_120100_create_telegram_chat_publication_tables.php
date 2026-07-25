<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_chats', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('telegram_chat_id')->unique();
            $table->string('title')->nullable();
            $table->string('type', 32)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('publishes_coordination')->default(true);
            $table->timestampsTz();
        });

        Schema::create('telegram_coordination_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('coordination_polls')->cascadeOnDelete();
            $table->foreignId('chat_id')->constrained('telegram_chats')->cascadeOnDelete();
            $table->bigInteger('message_id')->nullable();
            $table->string('telegram_poll_id')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->text('last_error')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['poll_id', 'chat_id'], 'telegram_coordination_poll_chat_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_coordination_publications');
        Schema::dropIfExists('telegram_chats');
    }
};
