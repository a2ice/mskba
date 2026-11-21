<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->boolean('discussion_enabled')->default(true);
            $table->boolean('report_comments_enabled')->default(true);
        });
        Schema::create('event_report_reactions', function (Blueprint $table): void {
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reaction', 8);
            $table->timestampTz('updated_at');
            $table->primary(['event_id', 'user_id']);
            $table->index(['event_id', 'reaction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_report_reactions');
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['discussion_enabled', 'report_comments_enabled']);
        });
    }
};
