<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_questions')) {
            return;
        }

        // Refuse to discard already received questions in any environment.
        if (DB::table('support_questions')->exists()) {
            throw new RuntimeException('Cannot drop support_questions: existing questions require manual review.');
        }

        Schema::drop('support_questions');
    }

    public function down(): void
    {
        if (Schema::hasTable('support_questions')) {
            return;
        }

        Schema::create('support_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('topic', 40);
            $table->string('source_path', 255);
            $table->text('body');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }
};
