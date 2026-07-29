<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32)->default('material');
            $table->string('title');
            $table->string('alias')->unique();
            $table->text('short_description');
            $table->longText('full_description');
            $table->string('link_url', 2048)->nullable();
            $table->string('related_type', 32)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->boolean('publish_in_feed')->default(false);
            $table->boolean('publish_in_telegram')->default(false);
            $table->timestamp('feed_published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['publish_in_feed', 'feed_published_at']);
            $table->index(['related_type', 'related_id']);
        });

        Schema::create('telegram_content_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_id')->constrained('telegram_chats')->cascadeOnDelete();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('status', 24)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['content_item_id', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_content_publications');
        Schema::dropIfExists('content_items');
    }
};
