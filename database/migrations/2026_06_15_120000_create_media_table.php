<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->string('mediable_type');
            $table->unsignedBigInteger('mediable_id');
            $table->string('collection')->default('gallery');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['mediable_type', 'mediable_id']);
            $table->index(['mediable_type', 'mediable_id', 'collection', 'is_featured'], 'media_mediable_collection_featured_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
