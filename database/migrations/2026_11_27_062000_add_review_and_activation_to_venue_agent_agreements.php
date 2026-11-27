<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_agent_agreements', function (Blueprint $table): void {
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('evidence_reference', 500)->nullable();
            $table->text('review_reason')->nullable();
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->boolean('active_marker')->nullable();
            $table->unique(['venue_id', 'active_marker'], 'venue_agent_agreements_current_unique');
        });
    }

    public function down(): void
    {
        Schema::table('venue_agent_agreements', function (Blueprint $table): void {
            $table->dropUnique('venue_agent_agreements_current_unique');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropConstrainedForeignId('activated_by_user_id');
            $table->dropColumn(['reviewed_at', 'evidence_reference', 'review_reason', 'activated_at', 'active_marker']);
        });
    }
};
