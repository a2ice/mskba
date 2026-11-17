<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_courts', function (Blueprint $table): void {
            // Reserved for a future automation flow. Never consumed by a scheduler or listener.
            $table->boolean('auto_sync_enabled')->default(false);
            $table->boolean('sync_include_prices_default')->default(true);
            $table->unsignedSmallInteger('sync_period_days')->default(7);
        });
    }

    public function down(): void
    {
        Schema::table('venue_courts', function (Blueprint $table): void {
            $table->dropColumn(['auto_sync_enabled', 'sync_include_prices_default', 'sync_period_days']);
        });
    }
};
