<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('views_count')->default(0);
        });
        Schema::create('content_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['content_item_id', 'is_published', 'id']);
            $table->index(['content_item_id', 'user_id', 'created_at']);
        });
        Schema::table('favorites', function (Blueprint $table): void {
            $table->index(['entity_type', 'entity_id', 'trashed_at'], 'favorites_entity_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('favorites', function (Blueprint $table): void {
            $table->dropIndex('favorites_entity_active_idx');
        });
        Schema::dropIfExists('content_comments');
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn('views_count');
        });
    }
};
