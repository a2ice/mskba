<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('play_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->boolean('include_similar_venues')->default(false);
            $table->decimal('center_latitude', 10, 7)->nullable();
            $table->decimal('center_longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('radius_km')->nullable();
            $table->string('cadence', 16)->default('flexible');
            $table->json('weekdays')->nullable();
            $table->json('dates')->nullable();
            $table->json('windows')->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_until')->nullable();
            $table->json('formats')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('access_mode', 12)->default('any');
            $table->json('amenity_preferences')->nullable();
            $table->boolean('notify_matches')->default(true);
            $table->boolean('notify_offers')->default(false);
            $table->string('status', 16)->default('active');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status', 'updated_at']);
            $table->index(['venue_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('play_intents');
    }
};
