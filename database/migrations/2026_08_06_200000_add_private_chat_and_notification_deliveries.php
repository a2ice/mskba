<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_accounts', function (Blueprint $table): void {
            $table->bigInteger('private_chat_id')->nullable()->after('telegram_user_id');
            $table->timestamp('private_chat_started_at')->nullable()->after('private_chat_id');
            $table->timestamp('private_chat_available_at')->nullable()->after('private_chat_started_at');
            $table->timestamp('private_chat_unavailable_at')->nullable()->after('private_chat_available_at');
            $table->text('last_delivery_error')->nullable()->after('private_chat_unavailable_at');
        });

        Schema::create('user_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_notification_id')->constrained('user_notifications')->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('status', 32)->default('pending');
            $table->string('recipient', 191)->nullable();
            $table->string('external_message_id', 191)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_notification_id', 'channel'], 'notification_delivery_channel_unique');
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_deliveries');

        Schema::table('telegram_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'private_chat_id',
                'private_chat_started_at',
                'private_chat_available_at',
                'private_chat_unavailable_at',
                'last_delivery_error',
            ]);
        });
    }
};
