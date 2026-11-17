<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('play_intents', function (Blueprint $table): void {
            // Existing interests never become publicly identifiable by accident.
            $table->boolean('show_on_venue')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('play_intents', function (Blueprint $table): void {
            $table->dropColumn('show_on_venue');
        });
    }
};
