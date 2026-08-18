<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reaction_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('source', 32);
            $table->string('source_key', 160);
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('dislikes_count')->default(0);
            $table->timestampTz('source_occurred_at')->nullable();
            $table->unsignedBigInteger('source_sequence')->nullable();
            $table->json('source_metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['subject_type', 'subject_id', 'source', 'source_key'],
                'reaction_aggregates_subject_source_unique',
            );
            $table->index(['subject_type', 'subject_id'], 'reaction_aggregates_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reaction_aggregates');
    }
};
