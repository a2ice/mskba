<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('play_intents', function (Blueprint $table): void {
            $table->boolean('notify_venue_news')->default(true);
            $table->json('game_delivery_channels')->nullable();
            $table->json('venue_delivery_channels')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('play_intents', function (Blueprint $table): void {
            $table->dropColumn(['notify_venue_news', 'game_delivery_channels', 'venue_delivery_channels']);
        });
    }
};
