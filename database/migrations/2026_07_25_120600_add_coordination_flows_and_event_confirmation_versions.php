<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coordination_sessions', function (Blueprint $table): void {
            $table->string('flow_type', 32)->default('single')->after('status')->index();
        });

        Schema::table('coordination_polls', function (Blueprint $table): void {
            $table->unsignedSmallInteger('step_order')->default(1)->after('session_id');
            $table->foreignId('depends_on_poll_id')
                ->nullable()
                ->after('step_order')
                ->constrained('coordination_polls')
                ->nullOnDelete();
            $table->unsignedSmallInteger('voting_duration_minutes')->default(60)->after('is_anonymous');
            $table->jsonb('configuration')->nullable()->after('voting_duration_minutes');
            $table->unique(['session_id', 'step_order']);
        });

        Schema::table('coordination_decisions', function (Blueprint $table): void {
            $table->dropUnique('coordination_decisions_session_id_unique');
            $table->index('session_id');
            $table->unique('poll_id');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->unsignedInteger('participation_confirmation_version')->default(1);
        });

        Schema::table('event_participants', function (Blueprint $table): void {
            $table->unsignedInteger('confirmation_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->dropColumn('confirmation_version');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('participation_confirmation_version');
        });

        Schema::table('coordination_decisions', function (Blueprint $table): void {
            $table->dropUnique('coordination_decisions_poll_id_unique');
            $table->dropIndex('coordination_decisions_session_id_index');
            $table->unique('session_id');
        });

        Schema::table('coordination_polls', function (Blueprint $table): void {
            $table->dropUnique('coordination_polls_session_id_step_order_unique');
            $table->dropConstrainedForeignId('depends_on_poll_id');
            $table->dropColumn(['step_order', 'voting_duration_minutes', 'configuration']);
        });

        Schema::table('coordination_sessions', function (Blueprint $table): void {
            $table->dropIndex('coordination_sessions_flow_type_index');
            $table->dropColumn('flow_type');
        });
    }
};
