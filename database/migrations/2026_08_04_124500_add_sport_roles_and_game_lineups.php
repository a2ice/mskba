<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_memberships', function (Blueprint $table): void {
            $table->string('member_type', 24)->default('player')->after('access_level');
            $table->boolean('is_captain')->default(false)->after('member_type');
            $table->boolean('is_default_starter')->default(false)->after('is_captain');
            $table->index(['scope_type', 'scope_id', 'member_type'], 'contract_memberships_scope_member_type_idx');
            $table->index(['scope_type', 'scope_id', 'is_captain'], 'contract_memberships_scope_captain_idx');
        });

        Schema::table('game_roster_entries', function (Blueprint $table): void {
            $table->string('lineup_role', 24)->default('bench')->after('status');
            $table->boolean('is_captain')->default(false)->after('lineup_role');
            $table->timestampTz('locked_at')->nullable()->after('is_captain');
            $table->index(['event_id', 'game_side_id', 'lineup_role'], 'game_roster_event_side_lineup_idx');
            $table->index(['event_id', 'game_side_id', 'is_captain'], 'game_roster_event_side_captain_idx');
        });
    }

    public function down(): void
    {
        Schema::table('game_roster_entries', function (Blueprint $table): void {
            $table->dropIndex('game_roster_event_side_captain_idx');
            $table->dropIndex('game_roster_event_side_lineup_idx');
            $table->dropColumn(['lineup_role', 'is_captain', 'locked_at']);
        });

        Schema::table('contract_memberships', function (Blueprint $table): void {
            $table->dropIndex('contract_memberships_scope_captain_idx');
            $table->dropIndex('contract_memberships_scope_member_type_idx');
            $table->dropColumn(['member_type', 'is_captain', 'is_default_starter']);
        });
    }
};
