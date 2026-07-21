<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->unsignedBigInteger('content_version')->default(0);
        });

        Schema::create('venue_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('created_by_actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->json('payload');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['venue_id', 'applied_at']);
        });

        Schema::table('moderation_requests', function (Blueprint $table): void {
            $table->foreignId('venue_revision_id')
                ->nullable()
                ->after('subject_id')
                ->constrained('venue_revisions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('moderation_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('venue_revision_id');
        });

        Schema::dropIfExists('venue_revisions');

        Schema::table('venues', function (Blueprint $table): void {
            $table->dropColumn('content_version');
        });
    }
};
