<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->char('guest_token_hash', 64)->nullable();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->timestampTz('trashed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'entity_type', 'entity_id'], 'favorites_user_entity_unique');
            $table->unique(['guest_token_hash', 'entity_type', 'entity_id'], 'favorites_guest_entity_unique');
            $table->index(['user_id', 'trashed_at', 'updated_at']);
            $table->index(['guest_token_hash', 'trashed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
