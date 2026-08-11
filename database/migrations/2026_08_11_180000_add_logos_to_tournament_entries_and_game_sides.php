<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_entries', function (Blueprint $table): void {
            $table->string('logo_preset', 32)->nullable()->after('name');
        });
        Schema::table('game_sides', function (Blueprint $table): void {
            $table->string('logo_preset', 32)->nullable()->after('display_name');
            $table->string('logo_disk', 64)->nullable()->after('logo_preset');
            $table->string('logo_path')->nullable()->after('logo_disk');
        });
    }

    public function down(): void
    {
        Schema::table('game_sides', fn (Blueprint $table) => $table->dropColumn(['logo_preset', 'logo_disk', 'logo_path']));
        Schema::table('tournament_entries', fn (Blueprint $table) => $table->dropColumn('logo_preset'));
    }
};
