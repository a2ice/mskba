<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_wall_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_type', 24);
            $table->unsignedBigInteger('scope_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 48);
            $table->text('body')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('client_request_id')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('hidden_at')->nullable();
            $table->timestampsTz();
            $table->index(['scope_type', 'scope_id', 'id'], 'social_wall_scope_position_idx');
            $table->unique(
                ['scope_type', 'scope_id', 'actor_user_id', 'client_request_id'],
                'social_wall_request_unique'
            );
        });

        // Exact sliding-window audit. A canonical user row is locked BEFORE checking
        // and inserting an action, so parallel tabs cannot bypass the quota.
        Schema::create('social_action_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope_type', 24);
            $table->unsignedBigInteger('scope_id');
            $table->string('action_type', 32);
            $table->timestampTz('created_at');
            $table->index(['user_id', 'created_at'], 'social_action_user_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_action_logs');
        Schema::dropIfExists('social_wall_entries');
    }
};
