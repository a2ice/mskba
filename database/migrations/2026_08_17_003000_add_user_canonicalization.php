<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table
                ->foreignId('canonical_user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::create('user_duplicates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('duplicate_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('score')->nullable();
            $table->char('evidence_hash', 64)->nullable();
            $table->char('resolved_evidence_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'duplicate_user_id']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('user_duplicate_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_duplicate_id')->constrained('user_duplicates')->cascadeOnDelete();
            $table->string('type', 64);
            $table->char('value_hash', 64);
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['user_duplicate_id', 'type', 'value_hash'], 'user_duplicate_evidence_unique');
            $table->index(['user_duplicate_id', 'is_active'], 'user_duplicate_evidence_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_duplicate_evidence');
        Schema::dropIfExists('user_duplicates');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('canonical_user_id');
        });
    }
};
