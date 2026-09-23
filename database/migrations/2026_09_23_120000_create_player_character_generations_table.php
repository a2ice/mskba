<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_character_generations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 32)->index();
            $table->string('status', 24)->default('pending')->index();
            $table->json('reference_media_ids');
            $table->json('payload_snapshot');
            $table->string('result_disk', 32)->nullable();
            $table->string('result_path')->nullable();
            $table->string('result_mime', 64)->nullable();
            $table->unsignedBigInteger('result_size')->nullable();
            $table->string('provider_run_id')->nullable()->index();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_character_generations');
    }
};
