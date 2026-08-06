<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_game_routes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_event_id')->unique();
            $table->string('legacy_identifier')->unique();
            $table->foreignId('game_id')->unique()->constrained('games')->cascadeOnDelete();
            $table->timestamps();
        });

        DB::table('games')
            ->join('events as legacy_events', 'legacy_events.id', '=', 'games.legacy_event_id')
            ->whereColumn('games.event_id', '!=', 'games.legacy_event_id')
            ->whereNotNull('legacy_events.parent_event_id')
            ->select(['games.id as game_id', 'legacy_events.id', 'legacy_events.alias'])
            ->orderBy('legacy_events.id')
            ->get()
            ->each(function (object $row): void {
                DB::table('legacy_game_routes')->insert([
                    'legacy_event_id' => $row->id,
                    'legacy_identifier' => $row->id.'-'.$row->alias,
                    'game_id' => $row->game_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_game_routes');
    }
};
