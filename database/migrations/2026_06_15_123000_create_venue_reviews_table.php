<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_reviews', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('venue_id')
                ->constrained('venues')
                ->cascadeOnDelete();
            $table
                ->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['venue_id', 'is_published', 'published_at']);
            $table->index(['venue_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_reviews');
    }
};
