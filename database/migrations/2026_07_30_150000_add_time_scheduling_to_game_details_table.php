<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_details', function (Blueprint $table): void {
            $table->boolean('is_time_scheduled')
                ->default(true)
                ->after('side_b_size');
        });
    }

    public function down(): void
    {
        Schema::table('game_details', function (Blueprint $table): void {
            $table->dropColumn('is_time_scheduled');
        });
    }
};
