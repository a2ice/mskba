<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('public_code', 64)->unique();
            $table->string('name', 160);
            $table->string('channel', 40)->index();
            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->unsignedInteger('verification_radius_m')->default(250);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('acquisition_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained('acquisition_campaigns')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->index();
            $table->string('channel', 40)->index();
            $table->string('source', 100)->nullable()->index();
            $table->string('medium', 100)->nullable();
            $table->string('campaign_name', 160)->nullable();
            $table->string('content', 160)->nullable();
            $table->string('term', 160)->nullable();
            $table->string('persona', 40)->nullable()->index();
            $table->text('landing_path');
            $table->text('referrer')->nullable();
            $table->string('location_status', 32)->default('not_requested')->index();
            $table->decimal('location_accuracy_m', 10, 2)->nullable();
            $table->unsignedInteger('distance_to_venue_m')->nullable();
            $table->timestamp('location_verified_at')->nullable();
            $table->timestamp('visited_at')->index();
            $table->timestamp('linked_at')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'visited_at']);
            $table->index(['user_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_visits');
        Schema::dropIfExists('acquisition_campaigns');
    }
};
