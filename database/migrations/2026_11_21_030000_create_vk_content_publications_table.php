<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vk_content_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('post_id')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vk_content_publications');
    }
};
