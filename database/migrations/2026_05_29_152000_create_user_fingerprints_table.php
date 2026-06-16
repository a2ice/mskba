<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint_hash', 64)->unique();
            $table->string('browser_signature_hash', 64)->nullable()->index();
            $table->string('ip_hash', 64)->nullable();
            $table->unsignedInteger('visits_count')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_fingerprint_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_fingerprint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('authentications_count')->default(0);
            $table->timestamp('first_authenticated_at')->nullable();
            $table->timestamp('last_authenticated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_fingerprint_id', 'user_id']);
            $table->index(['user_id', 'last_authenticated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_fingerprint_user');
        Schema::dropIfExists('user_fingerprints');
    }
};
