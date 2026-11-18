<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('token_hash', 64)->unique();
            $table->string('contact_type', 24)->default('email');
            $table->string('contact_value');
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id');
            $table->string('role', 48);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('progress', 40)->default('created');
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('contact_verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scope_type', 'scope_id', 'status']);
            $table->index(['contact_type', 'contact_value']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('account_invitation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invitation_id')->constrained('account_invitations')->cascadeOnDelete();
            $table->string('event', 48);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['invitation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitation_events');
        Schema::dropIfExists('account_invitations');
    }
};
