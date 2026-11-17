<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('status', 20)->default('pending');
            $table->json('original_data');
            $table->json('proposed_data');
            $table->text('reason');
            $table->text('review_reason')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->foreignId('unblocked_by_user_id')->nullable()->constrained('users');
            $table->timestamp('unblocked_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('profile_change_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_change_request_id')->constrained('profile_change_requests');
            $table->foreignId('actor_user_id')->constrained('users');
            $table->string('action', 20);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_change_request_events');
        Schema::dropIfExists('profile_change_requests');
    }
};
