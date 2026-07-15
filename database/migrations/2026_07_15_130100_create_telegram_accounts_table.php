<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('language_code', 16)->nullable();
            $table->string('photo_url', 2048)->nullable();
            $table->timestamp('last_auth_at')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('username');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_accounts');
    }
};
