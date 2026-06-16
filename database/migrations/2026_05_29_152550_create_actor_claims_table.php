<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actor_claims', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('claimed_actor_id')
                ->constrained('actors')
                ->cascadeOnDelete();
            $table
                ->foreignId('claimed_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table
                ->foreignId('claimed_by_actor_id')
                ->nullable()
                ->constrained('actors')
                ->nullOnDelete();
            $table->timestamp('claimed_at');
            $table->timestamps();

            $table->unique('claimed_actor_id');
            $table->index(['claimed_by_user_id', 'claimed_at']);
            $table->index('claimed_by_actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actor_claims');
    }
};
