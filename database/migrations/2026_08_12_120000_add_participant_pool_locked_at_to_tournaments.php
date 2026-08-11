<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->timestampTz('participant_pool_locked_at')->nullable()->after('accepts_unconfirmed_participants');
        });

        DB::table('tournaments')->whereExists(function ($query): void {
            $query->selectRaw('1')->from('tournament_entries')
                ->whereColumn('tournament_entries.tournament_id', 'tournaments.id')
                ->where('source', 'assembled');
        })->update(['participant_pool_locked_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('tournaments', fn (Blueprint $table) => $table->dropColumn('participant_pool_locked_at'));
    }
};
