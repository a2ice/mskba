<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_self_assessments', function (Blueprint $table): void {
            $table->unsignedTinyInteger('close_range_shooting')->nullable();
            $table->unsignedTinyInteger('mid_range_shooting')->nullable();
            $table->unsignedTinyInteger('long_range_shooting')->nullable();
        });

        DB::table('player_self_assessments')->update([
            'close_range_shooting' => DB::raw('shooting'),
            'mid_range_shooting' => DB::raw('shooting'),
            'long_range_shooting' => DB::raw('shooting'),
        ]);

        Schema::table('player_self_assessments', function (Blueprint $table): void {
            $table->dropColumn('shooting');
        });
    }

    public function down(): void
    {
        Schema::table('player_self_assessments', function (Blueprint $table): void {
            $table->unsignedTinyInteger('shooting')->nullable();
        });

        DB::table('player_self_assessments')->update([
            'shooting' => DB::raw(
                'COALESCE(mid_range_shooting, close_range_shooting, long_range_shooting)',
            ),
        ]);

        Schema::table('player_self_assessments', function (Blueprint $table): void {
            $table->dropColumn([
                'close_range_shooting',
                'mid_range_shooting',
                'long_range_shooting',
            ]);
        });
    }
};
