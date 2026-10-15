<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            // Marketing is intentionally not enabled. Only service conversations.
            $table->string('purpose', 24)->default('service');
            $table->timestamps();
            $table->unique(['operator_user_id', 'recipient_user_id'], 'operator_conversation_pair');
            $table->index(['recipient_user_id', 'updated_at']);
        });

        Schema::create('operator_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operator_conversation_id')->constrained('operator_conversations')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['operator_conversation_id', 'id'], 'operator_message_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_messages');
        Schema::dropIfExists('operator_conversations');
    }
};
