<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenities', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('alias', 120)->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('venue_amenities', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('venue_id')
                ->constrained('venues')
                ->cascadeOnDelete();
            $table
                ->foreignId('amenity_id')
                ->constrained('amenities')
                ->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['venue_id', 'amenity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_amenities');
        Schema::dropIfExists('amenities');
    }
};
