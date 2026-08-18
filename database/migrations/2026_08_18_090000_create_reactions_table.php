<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactions', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('actor_type', 32);
            $table->string('actor_id', 64);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->smallInteger('value');
            $table->string('source', 32);
            $table->timestamp('source_occurred_at')->nullable();
            $table->unsignedBigInteger('source_sequence')->nullable();
            $table->json('source_metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['subject_type', 'subject_id', 'actor_type', 'actor_id'],
                'reactions_subject_actor_unique',
            );
            $table->index(['subject_type', 'subject_id', 'value'], 'reactions_subject_value_index');
            $table->index(['user_id', 'subject_type'], 'reactions_user_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }
};
