<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->string('responsibility_status', 24)->nullable()->after('confirmation_version');
            $table->foreignId('responsibility_requested_by_user_id')
                ->nullable()
                ->after('responsibility_status')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('responsibility_requested_at')->nullable();
            $table->timestampTz('responsibility_responded_at')->nullable();
            $table->index(['event_id', 'responsibility_status'], 'event_participants_responsibility_index');
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->dropIndex('event_participants_responsibility_index');
            $table->dropConstrainedForeignId('responsibility_requested_by_user_id');
            $table->dropColumn([
                'responsibility_status',
                'responsibility_requested_at',
                'responsibility_responded_at',
            ]);
        });
    }
};
